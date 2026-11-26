<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kanvas\Intelligence\Agents\Actions\RequestAgentApprovalAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\DeliverSignalToRecordAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\MatchSignalToRecordsAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\ResolveExternalAttendeesAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\RouteSignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\SegmentSignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Approval\ProjectRoutingApprovalHandler;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingDecision;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingOutcomeEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingTargetTypeEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\SignalDeliveryStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\Actions\StoreSignalFileAction;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Throwable;

/**
 * The company-level orchestrator endpoint: one `ReceiverWebhook` per company (NOT bound to a project)
 * receives unlabeled signals — meeting transcripts, emails, CRM events, … — and routes each to the
 * record that owns it. The receiver's `configuration.signal_source` selects the adapter, and
 * `configuration.destinations` which levels it routes to (`lead`, `organization`, `project`; default
 * projects only). The routing cascade (`RouteSignalAction`) decides forward / approval / triage / drop.
 * A project forward reuses `IngestToProjectAction` (which dedups and wakes the PM); a lead or
 * organization forward is `DeliverSignalToRecordAction`. Every outcome is recorded in the ledger.
 *
 * Contrast with `ProcessProjectWebhookJob`, which is bound to ONE project via `configuration.project_id`.
 */
#[WorkflowAction(name: 'Orchestrator Signal Ingest')]
class ProcessOrchestratorSignalJob extends ProcessWebhookJob
{
    /** Receiver configuration key: the levels this receiver routes to. */
    public const string DESTINATIONS = 'destinations';

    /**
     * Set once outside attendees are known: a meeting where everyone is staff never waits in the
     * Inbox for a human — with no project match it is dropped.
     */
    private bool $internalOnly = false;

    /** The whole signal's file, shared by every message it is routed to — segments included. */
    private ?StoreSignalFileAction $archive = null;

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $source = self::configuredSource((array) ($receiver->configuration ?? []));

        // A receiver with no valid source is reported by execute(), not refused here.
        return $source === null || $source->adapter()->authenticate($request, $receiver->company);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload ?? [];
        /** @var array<string, mixed> $config */
        $config = $this->receiver->configuration ?? [];

        $source = self::configuredSource($config);
        if ($source === null) {
            return ['status' => 'error', 'reason' => 'receiver has no valid signal_source'];
        }

        $signal = SignalSourceEnum::parseWithFallback($payload, $source);
        if ($signal === null) {
            return ['status' => 'ignored', 'reason' => 'empty content'];
        }

        $this->archive = new StoreSignalFileAction($signal, $this->receiver);

        $candidates = $this->candidateProjects($config);
        $records = $this->matchRecords($signal, $config);

        // A meeting with a customer is about that customer as a whole, and one with a single project
        // (or none) has nothing to fan out across — route the signal whole.
        if ($records !== [] || count($candidates) <= 1) {
            return $this->route($signal, $candidates, $records);
        }

        // Split multi-topic signals so each project receives only its relevant segments.
        $segments = new SegmentSignalAction($signal)->execute();
        if (count($segments) === 1) {
            return $this->route($segments[0], $candidates);
        }

        return $this->fanOut($segments, $candidates);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configuredSource(array $config): ?SignalSourceEnum
    {
        return SignalSourceEnum::tryFrom((string) ($config['signal_source'] ?? $config['type'] ?? ''));
    }

    /**
     * The leads and organizations an outside attendee belongs to, when the receiver routes to them.
     *
     * @param array<string, mixed> $config
     *
     * @return list<RoutingCandidate>
     */
    private function matchRecords(InboundSignal $signal, array $config): array
    {
        $types = $this->recordTypes($config);
        if ($types === []) {
            return [];
        }

        $external = new ResolveExternalAttendeesAction($this->receiver->app, $this->receiver->company, array_values(array_map('strval', (array) ($config['internal_domains'] ?? []))))->execute($signal);

        $this->internalOnly = $signal->actorEmails() !== [] && $external === [];

        return new MatchSignalToRecordsAction(
            $this->receiver->app,
            $this->receiver->company,
            $external,
            $types
        )->execute();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<RoutingTargetTypeEnum>
     */
    private function recordTypes(array $config): array
    {
        $types = array_map(
            static fn (mixed $type): ?RoutingTargetTypeEnum => RoutingTargetTypeEnum::tryFrom((string) $type),
            (array) ($config[self::DESTINATIONS] ?? [RoutingTargetTypeEnum::PROJECT->value])
        );

        return array_values(array_filter(
            $types,
            static fn (?RoutingTargetTypeEnum $type): bool => $type !== null && $type !== RoutingTargetTypeEnum::PROJECT
        ));
    }

    /**
     * Route one signal (or segment) to its outcome and act on it.
     *
     * @param list<Project> $candidates
     * @param list<RoutingCandidate> $records
     *
     * @return array<string, mixed>
     */
    private function route(InboundSignal $signal, array $candidates, array $records = []): array
    {
        $decision = $this->decide($signal, $candidates, $records);

        return match ($decision->outcome) {
            RoutingOutcomeEnum::FORWARD => $this->forward($signal, $decision),
            RoutingOutcomeEnum::APPROVAL,
            RoutingOutcomeEnum::TRIAGE => $this->escalate($signal, $decision),
            RoutingOutcomeEnum::DROP => $this->record('dropped', $signal, $decision),
        };
    }

    /**
     * @param list<Project> $candidates
     * @param list<RoutingCandidate> $records
     */
    private function decide(InboundSignal $signal, array $candidates, array $records = []): RoutingDecision
    {
        $decision = new RouteSignalAction($signal)->execute($candidates, $records);

        if ($this->internalOnly && $decision->outcome === RoutingOutcomeEnum::TRIAGE) {
            return RoutingDecision::drop(sprintf('Internal %s, no project match.', $signal->kind->noun()));
        }

        return $decision;
    }

    /**
     * Route each segment independently, then coalesce forwards so a project owning several segments is
     * woken once (one merged ingest) instead of per segment. Escalations and drops stay per-segment —
     * each is its own unit of human attention.
     *
     * @param list<InboundSignal> $segments
     * @param list<Project> $candidates
     *
     * @return array<string, mixed>
     */
    private function fanOut(array $segments, array $candidates): array
    {
        /** @var array<int, list<array{0: InboundSignal, 1: RoutingDecision}>> $forwardsByProject */
        $forwardsByProject = [];
        $results = [];

        foreach ($segments as $segment) {
            $decision = $this->decide($segment, $candidates);
            $project = $decision->project();

            if ($decision->outcome === RoutingOutcomeEnum::FORWARD && $project !== null) {
                $forwardsByProject[$project->getId()][] = [$segment, $decision];

                continue;
            }

            $results[] = match ($decision->outcome) {
                RoutingOutcomeEnum::APPROVAL,
                RoutingOutcomeEnum::TRIAGE => $this->escalate($segment, $decision),
                default => $this->record('dropped', $segment, $decision),
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
     * @return array<string, mixed>
     */
    private function escalate(InboundSignal $signal, RoutingDecision $decision): array
    {
        $status = $decision->outcome->value;
        $suggested = $decision->outcome === RoutingOutcomeEnum::APPROVAL ? $decision->project()?->getId() : null;

        $config = $this->config();
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

        $file = $this->archive?->execute();

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
                'body' => $file !== null ? $signal->digest($file) : null,
                'filesystem_id' => $file?->getId(),
                'external_id' => $signal->externalId,
                'source' => $signal->source->value,
            ],
            verb: 'orchestrator-approval',
            entity: $inbox,
        )->execute();

        if ($file !== null) {
            $request->addFile($file, $file->name);
        }

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
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) ($this->receiver->configuration ?? []);
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
        $project = $decision->project();

        if ($decision->outcome === RoutingOutcomeEnum::APPROVAL && $project !== null) {
            return sprintf(
                'New %s "%s" — I think this belongs to **%s** (%.0f%% sure). Approve to route it '
                . 'there, or reply with the right project.',
                $kind,
                $signal->title,
                $project->title,
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
        $project = $decision->project();
        if ($project === null) {
            return $this->deliverToRecord($signal, $decision);
        }

        $file = $this->archive?->execute();

        $ingest = new IngestToProjectAction(
            project: $project,
            type: $signal->kind,
            content: $signal->content,
            body: $file !== null ? $signal->digest($file) : null,
            storedFiles: $file !== null ? [$file] : [],
        );
        $message = $ingest->execute();

        $this->emit(
            'orchestrator.signal.routed',
            $signal,
            $decision,
            $project,
            ['duplicate' => $ingest->wasDuplicate()],
        );

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
     * @return array<string, mixed>
     */
    private function deliverToRecord(InboundSignal $signal, RoutingDecision $decision): array
    {
        /** @var RoutingCandidate $target */
        $target = $decision->target;

        $delivery = new DeliverSignalToRecordAction($target, $signal, $this->archive)->execute();

        $eventType = match ($delivery->status) {
            SignalDeliveryStatusEnum::DELIVERED,
            SignalDeliveryStatusEnum::DUPLICATE => 'orchestrator.signal.routed',
            SignalDeliveryStatusEnum::NO_AGENT => 'orchestrator.signal.skipped',
            SignalDeliveryStatusEnum::FAILED => 'orchestrator.signal.failed',
        };

        $this->emit(
            $eventType,
            $signal,
            $decision,
            $target->record,
            [
                'duplicate' => $delivery->status === SignalDeliveryStatusEnum::DUPLICATE,
                'delivery' => $delivery->status->value,
                'agent_id' => $delivery->agent?->getId(),
                'message_id' => $delivery->messageId,
                'delivery_reason' => $delivery->reason,
            ],
        );

        $this->feedToInbox($signal, sprintf(
            '%s %s "%s" (#%d) — %s',
            $delivery->status->value,
            $target->type->value,
            $target->label(),
            $target->id(),
            $delivery->reason,
        ));

        return [
            'status' => $delivery->status->value,
            'outcome' => RoutingOutcomeEnum::FORWARD->value,
            'reason' => $decision->reason,
            'target' => $target->key(),
            $target->type->value . '_id' => $target->id(),
            'agent_id' => $delivery->agent?->getId(),
            'message_id' => $delivery->messageId,
            'delivery_reason' => $delivery->reason,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function record(
        string $status,
        InboundSignal $signal,
        RoutingDecision $decision,
        array $extra = [],
    ): array {
        $this->emit(
            eventType: "orchestrator.signal.{$status}",
            signal: $signal,
            decision: $decision,
            entity: null,
            extra: $extra,
        );

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
        $config = $this->config();
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
        ?Model $entity,
        array $extra,
    ): void {
        $agentId = (int) ($this->config()['orchestrator_agent_id'] ?? 0);

        try {
            new AppendEventAction(
                new EventData(
                    app: $this->receiver->app,
                    company: $this->receiver->company,
                    sourceDomain: 'Orchestrator',
                    eventType: $eventType,
                    status: EventStatusEnum::INFO,
                    sourceEntityType: $entity !== null ? $entity::class : null,
                    sourceEntityId: $entity?->getKey(),
                    actorType: 'Agent',
                    actorId: $agentId > 0 ? $agentId : null,
                    payload: $extra + [
                        'source' => $signal->source->value,
                        'kind' => $signal->kind->value,
                        'external_id' => $signal->externalId,
                        'title' => $signal->title,
                        'reason' => $decision->reason,
                        'target' => $decision->target?->key(),
                        'signed' => $signal->source->adapter()->isSigned($this->receiver->company),
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
