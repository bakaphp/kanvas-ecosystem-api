<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Actions\RequestAgentApprovalAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\RouteSignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\SegmentSignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Approval\ProjectRoutingApprovalHandler;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingDecision;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingOutcomeEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

/**
 * The company-level orchestrator endpoint: one `ReceiverWebhook` per company (NOT bound to a project)
 * receives unlabeled signals — meeting transcripts, emails, CRM events, … — and routes each to the
 * right project's PM. The receiver's `configuration.signal_source` selects the adapter; the routing
 * cascade (`RouteSignalAction`) decides forward / approval / triage / drop. Forward reuses
 * `IngestToProjectAction` (which dedups and wakes the target PM). Every outcome is recorded in the
 * ledger for observability.
 *
 * Contrast with `ProcessProjectWebhookJob`, which is bound to ONE project via `configuration.project_id`.
 */
#[WorkflowAction(name: 'Orchestrator Signal Ingest')]
class ProcessOrchestratorSignalJob extends ProcessWebhookJob
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload ?? [];
        /** @var array<string, mixed> $config */
        $config = $this->receiver->configuration ?? [];

        $source = SignalSourceEnum::tryFrom((string) ($config['signal_source'] ?? $config['type'] ?? ''));
        if ($source === null) {
            return ['status' => 'error', 'reason' => 'receiver has no valid signal_source'];
        }

        $signal = SignalSourceEnum::parseWithFallback($payload, $source);
        if ($signal === null) {
            return ['status' => 'ignored', 'reason' => 'empty content'];
        }

        $candidates = $this->candidateProjects($config);

        // One candidate (or none) — nothing to fan out across; route the signal whole.
        if (count($candidates) <= 1) {
            return $this->route(
                $signal,
                $candidates,
                $config
            );
        }

        // Multi-topic fan-out: split the signal, route each segment independently. A single-topic
        // signal collapses back to one segment and routes exactly as before.
        $segments = new SegmentSignalAction($signal)->execute();
        if (count($segments) === 1) {
            return $this->route(
                $segments[0],
                $candidates,
                $config
            );
        }

        return $this->fanOut(
            $segments,
            $candidates,
            $config
        );
    }

    /**
     * Route one signal (or segment) to its outcome and act on it.
     *
     * @param list<Project> $candidates
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function route(InboundSignal $signal, array $candidates, array $config): array
    {
        $decision = new RouteSignalAction($signal)->execute($candidates);

        return match ($decision->outcome) {
            RoutingOutcomeEnum::FORWARD => $this->forward($signal, $decision),
            RoutingOutcomeEnum::APPROVAL,
            RoutingOutcomeEnum::TRIAGE => $this->escalate($signal, $decision, $config),
            RoutingOutcomeEnum::DROP => $this->record('dropped', $signal, $decision, []),
        };
    }

    /**
     * Route each segment independently, then coalesce forwards so a project owning several segments is
     * woken once (one merged ingest) instead of per segment. Escalations and drops stay per-segment —
     * each is its own unit of human attention.
     *
     * @param list<InboundSignal> $segments
     * @param list<Project> $candidates
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function fanOut(array $segments, array $candidates, array $config): array
    {
        /** @var array<int, list<array{0: InboundSignal, 1: RoutingDecision}>> $forwardsByProject */
        $forwardsByProject = [];
        $results = [];

        foreach ($segments as $segment) {
            $decision = new RouteSignalAction($segment)->execute($candidates);

            if ($decision->outcome === RoutingOutcomeEnum::FORWARD && $decision->project !== null) {
                $forwardsByProject[$decision->project->getId()][] = [$segment, $decision];

                continue;
            }

            $results[] = match ($decision->outcome) {
                RoutingOutcomeEnum::APPROVAL,
                RoutingOutcomeEnum::TRIAGE => $this->escalate($segment, $decision, $config),
                default => $this->record('dropped', $segment, $decision, []),
            };
        }

        foreach ($forwardsByProject as $group) {
            $results[] = $this->forwardGroup($group);
        }

        return [
            'status' => 'fan_out',
            'segments' => count($segments),
            'projects' => count($forwardsByProject),
            'results' => $results,
        ];
    }

    /**
     * Forward one project's segment(s). A single segment forwards as-is; several are merged under their
     * titles into one ingest so the PM is woken once with all of its relevant topics.
     *
     * @param list<array{0: InboundSignal, 1: RoutingDecision}> $group
     *
     * @return array<string, mixed>
     */
    private function forwardGroup(array $group): array
    {
        [$firstSegment, $decision] = $group[0];

        if (count($group) === 1) {
            return $this->forward($firstSegment, $decision);
        }

        $parts = [];
        foreach ($group as [$segment]) {
            $parts[] = "## {$segment->title}\n{$segment->content}";
        }

        return $this->forward($firstSegment->withContent(implode("\n\n", $parts)), $decision);
    }

    /**
     * APPROVAL / TRIAGE — the orchestrator won't touch a real board on a guess. Land the held signal in
     * the company Inbox as a LOCKED approval request (the general agent-approval primitive) for a human
     * to confirm/redirect; on approval, ProjectRoutingApprovalHandler forwards it. If the Inbox isn't
     * provisioned yet, fall back to a ledger record so the signal is never silently lost.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function escalate(InboundSignal $signal, RoutingDecision $decision, array $config): array
    {
        $status = $decision->outcome->value;
        $suggested = $decision->outcome === RoutingOutcomeEnum::APPROVAL ? $decision->project?->getId() : null;

        $inbox = $this->inboxProject($config);
        $author = $this->orchestratorUser($config);

        if ($inbox === null || $inbox->defaultChannel === null || $author === null) {
            // Inbox not ready — don't drop the signal, just record it for now.
            return $this->record(
                $status,
                $signal,
                $decision,
                ['suggested_project_id' => $suggested]
            );
        }

        $request = new RequestAgentApprovalAction(
            channel: $inbox->defaultChannel,
            author: $author,
            content: $this->approvalPrompt($signal, $decision),
            kind: ProjectRoutingApprovalHandler::KIND,
            handler: ProjectRoutingApprovalHandler::class,
            context: [
                'project_id' => $suggested ?? 0,
                'suggested_project_id' => $suggested,
                'ingest_type' => $signal->kind->value,
                'content' => $signal->content,
                'external_id' => $signal->externalId,
                'source' => $signal->source->value,
            ],
            verb: 'orchestrator-approval',
            entity: $inbox,
        )->execute();

        $this->emit(
            "orchestrator.signal.{$status}",
            $signal,
            $decision,
            $inbox,
            [
                'approval_message_id' => $request->getId(),
                'suggested_project_id' => $suggested,
                'confidence' => $decision->confidence,
            ]
        );

        return [
            'status' => $status,
            'outcome' => $decision->outcome->value,
            'reason' => $decision->reason,
            'approval_message_id' => $request->getId(),
            'suggested_project_id' => $suggested,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function inboxProject(array $config): ?Project
    {
        $inboxId = (int) ($config['inbox_project_id'] ?? 0);
        if ($inboxId <= 0) {
            return null;
        }

        return Project::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->where('id', $inboxId)
            ->first();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function orchestratorUser(array $config): ?Users
    {
        $agentId = (int) ($config['orchestrator_agent_id'] ?? 0);
        if ($agentId <= 0) {
            return null;
        }

        return Agent::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->where('id', $agentId)
            ->first()?->user;
    }

    private function approvalPrompt(InboundSignal $signal, RoutingDecision $decision): string
    {
        $kind = $signal->kind->value;

        if ($decision->outcome === RoutingOutcomeEnum::APPROVAL && $decision->project !== null) {
            return sprintf(
                'New %s "%s" — I think this belongs to **%s** (%.0f%% sure). Approve to route it '
                . 'there, or reply with the right project.',
                $kind,
                $signal->title,
                $decision->project->title,
                $decision->confidence * 100.0,
            );
        }

        return sprintf(
            "New %s \"%s\" — I couldn't confidently match this to a project. Please route it to the "
            . 'right one.',
            $kind,
            $signal->title,
        );
    }

    /**
     * The company's open projects — the routing candidate set. The orchestrator's own Inbox project
     * (if configured) is never a routing target.
     *
     * @param array<string, mixed> $config
     *
     * @return list<Project>
     */
    private function candidateProjects(array $config): array
    {
        $inboxId = (int) ($config['inbox_project_id'] ?? 0);

        return Project::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->whereIn('status', ProjectStatusEnum::openStatusValues())
            ->when(
                $inboxId > 0,
                fn (Builder $query): Builder => $query->where('id', '!=', $inboxId),
            )
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function forward(InboundSignal $signal, RoutingDecision $decision): array
    {
        /** @var Project $project */
        $project = $decision->project;

        $ingest = new IngestToProjectAction(
            project: $project,
            type: $signal->kind,
            content: $signal->content,
        );
        $message = $ingest->execute();

        $this->emit('orchestrator.signal.routed', $signal, $decision, $project, [
            'duplicate' => $ingest->wasDuplicate(),
        ]);

        $this->feedToInbox($signal, $ingest->wasDuplicate()
            ? sprintf('already on %s (#%d) — duplicate', $project->title, $project->getId())
            : sprintf('routed to %s (#%d)', $project->title, $project->getId()));

        return [
            'status' => $ingest->wasDuplicate() ? 'duplicate' : 'routed',
            'outcome' => RoutingOutcomeEnum::FORWARD->value,
            'reason' => $decision->reason,
            'project_id' => $project->getId(),
            'message_id' => $message->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function record(string $status, InboundSignal $signal, RoutingDecision $decision, array $extra): array
    {
        $this->emit("orchestrator.signal.{$status}", $signal, $decision, null, $extra);

        $this->feedToInbox($signal, sprintf('%s: %s', $status, $decision->reason));

        return [
            'status' => $status,
            'outcome' => $decision->outcome->value,
            'reason' => $decision->reason,
        ] + $extra;
    }

    /**
     * Post a one-line entry to the Inbox channel so the Inbox project reads as a human-visible feed of
     * every processed signal (routed / dropped), mirroring the receiver logs. Approval/triage already
     * land their own message in the Inbox, so they don't get a duplicate feed line. Non-locked, from the
     * orchestrator user, workflow suppressed (it's a log line, not work). No-ops when the Inbox isn't
     * provisioned — the ledger still has the full record.
     */
    private function feedToInbox(InboundSignal $signal, string $outcome): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->receiver->configuration;
        $inbox = $this->inboxProject($config);
        $author = $this->orchestratorUser($config);

        if ($inbox === null || $inbox->defaultChannel === null || $author === null) {
            return;
        }

        try {
            new PostChannelMessageAction(
                channel: $inbox->defaultChannel,
                author: $author,
                verb: 'orchestrator-signal-log',
                content: sprintf('📥 "%s" → %s', $signal->title, $outcome),
                extraPayload: ['from_ia' => true],
                runWorkflow: false,
                entity: $inbox,
                messageTypeName: 'orchestrator-signal-log',
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function emit(
        string $eventType,
        InboundSignal $signal,
        RoutingDecision $decision,
        ?Project $project,
        array $extra,
    ): void {
        $agentId = (int) ($this->receiver->configuration['orchestrator_agent_id'] ?? 0);

        try {
            new AppendEventAction(
                new EventData(
                    app: $this->receiver->app,
                    company: $this->receiver->company,
                    sourceDomain: 'Orchestrator',
                    eventType: $eventType,
                    status: EventStatusEnum::INFO,
                    sourceEntityType: $project !== null ? Project::class : null,
                    sourceEntityId: $project?->getId(),
                    actorType: 'Agent',
                    actorId: $agentId > 0 ? $agentId : null,
                    payload: $extra + [
                        'source' => $signal->source->value,
                        'kind' => $signal->kind->value,
                        'external_id' => $signal->externalId,
                        'title' => $signal->title,
                        'reason' => $decision->reason,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
