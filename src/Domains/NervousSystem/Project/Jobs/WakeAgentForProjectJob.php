<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Throwable;

/**
 * Wake the project's PM agent to advance the work. Called by:
 *   - IngestToProjectAction (a transcript/email/@mention landed) — REASON_INGEST
 *   - the heartbeat (PR5) — REASON_HEARTBEAT
 *
 * Assembles the project's context bundle, runs the PM through AgentChatKernel on a per-project
 * session (continuous LLM memory across wake-ups), and posts the reply back on the default channel.
 * The PM moves tasks via its agent tools (PR0). Emits project.agent.* ledger events.
 */
class WakeAgentForProjectJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON_INGEST = 'ingest';
    public const string REASON_HEARTBEAT = 'heartbeat';
    public const string REASON_ASSIGNED = 'assigned';

    public function __construct(
        public readonly Project $project,
        public readonly string $reason,
        public readonly ?string $triggerMessage = null,
    ) {
        $this->onQueue('nervous-system-project');
    }

    public function handle(): void
    {
        // Reset Bouncer scope + app to this project's app — else agent/channel Role lookups throw
        // under a leaked worker scope.
        $this->overwriteAppService($this->project->app);

        $agent = $this->project->pmAgent;
        $owner = $this->project->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        $session = $this->resolveSession();
        $message = $this->buildMessage();

        $startedAt = microtime(true);

        try {
            $response = new AgentChatKernel(
                agent: $agent,
                session: $session,
                message: $message,
                user: $owner,
            )->execute();
        } catch (Throwable $e) {
            $this->project->emitLedgerEvent(
                eventType: 'project.agent.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: [
                    'agent_id' => $this->project->agent_id,
                    'session_id' => $session->getId(),
                    'reason' => $this->reason,
                ],
                error: [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->project->emitLedgerEvent(
            'project.agent.invoked',
            payload: [
                'agent_id' => $this->project->agent_id,
                'session_id' => $session->getId(),
                'reason' => $this->reason,
                'response_length' => strlen($response),
            ],
            durationMs: $durationMs,
        );

        $reply = new PostProjectMessageAction(
            project: $this->project,
            verb: 'project-agent-reply',
            content: $response,
            author: $agent->user,
        )->execute();

        $this->project->emitLedgerEvent(
            'project.agent.replied',
            payload: [
                'agent_id' => $this->project->agent_id,
                'message_id' => $reply->getId(),
            ],
        );
    }

    protected function resolveSession(): Session
    {
        $owner = $this->project->user ?? $this->project->pmAgent?->user;

        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->project->apps_id,
                'companies_id' => $this->project->companies_id,
                'entity_namespace' => Project::class,
                'entity_id' => $this->project->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->project->agent_id,
                'channel_id' => $this->project->default_channel_id,
                'content' => '',
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
        );

        return $session;
    }

    protected function buildMessage(): string
    {
        $bundle = new ProjectContextService()->buildContextBundle($this->project, historyLimit: 20);

        $header = sprintf(
            "[NS:project reason=%s project_id=%d project_uuid=%s]\n\n",
            $this->reason,
            $this->project->getId(),
            $this->project->uuid,
        );

        $trigger = $this->triggerMessage !== null && $this->triggerMessage !== ''
            ? "New context on the project:\n\"\"\"\n{$this->triggerMessage}\n\"\"\"\n\n"
            : '';

        return $header
            . 'You are the PM of this project. Read the context below, decide what needs to happen, '
            . "then use your tools to create/assign/move tasks and keep the project moving.\n\n"
            . $trigger
            . 'Context: ' . (string) json_encode($bundle->toArray());
    }
}
