<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs;

use Baka\Traits\KanvasJobsTrait;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;

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
    use DrivesAgentWake;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON_INGEST = 'ingest';
    public const string REASON_HEARTBEAT = 'heartbeat';
    public const string REASON_ASSIGNED = 'assigned';
    public const string REASON_MENTION = 'mention';

    // A held wake lock auto-expires after this so a crashed/timed-out holder can't wedge the project.
    private const int WAKE_LOCK_TTL_SECONDS = 600;

    // A mention that collides with an in-flight wake re-queues after this delay instead of being dropped.
    private const int MENTION_RETRY_SECONDS = 15;

    // Space out exception-driven retries so a genuinely-failing wake doesn't hammer the LLM/ledger.
    // (WithoutOverlapping's releaseAfter for a mention lock-collision sets its own delay independently.)
    public int $backoff = 30;

    public function __construct(
        public readonly Project $project,
        public readonly string $reason,
        public readonly ?string $triggerMessage = null,
        public readonly ?int $triggerMessageId = null,
    ) {
        $this->onQueue('nervous-system-project');
    }

    /**
     * Bound retries by TIME, not attempt count. A mention re-queues (releaseAfter) every collision
     * until the in-flight PM turn frees the lock — give it the full lock-TTL window so a long turn
     * never drops the question. Other reasons don't self-release; this just caps their failure retries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(
            $this->reason === self::REASON_MENTION ? self::WAKE_LOCK_TTL_SECONDS : 90,
        );
    }

    /**
     * Serialize wakes per project: two concurrent PM turns waste an LLM call and can race.
     *
     * Automated wakes (ingest/heartbeat/assigned) DROP on collision — the in-flight wake already reads
     * fresh state and the heartbeat mops up anything mid-run. A human @mention is a DIRECT question,
     * so it must never be silently dropped: on collision it re-queues and answers as soon as the
     * in-flight turn frees the lock. The lock TTL guards against a crashed holder wedging the project.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $lock = new WithoutOverlapping('project-wake-' . $this->project->getId())
            ->expireAfter(self::WAKE_LOCK_TTL_SECONDS);

        return [
            $this->reason === self::REASON_MENTION
                ? $lock->releaseAfter(self::MENTION_RETRY_SECONDS)
                : $lock->dontRelease(),
        ];
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
        $failurePayload = [
            'agent_id' => $this->project->agent_id,
            'session_id' => $session->getId(),
            'reason' => $this->reason,
        ];

        [$response, $durationMs] = $this->runAgentWake(
            $agent,
            $session,
            $owner,
            $this->buildMessage(),
            $this->project,
            'project.agent',
            $failurePayload,
        );

        $this->project->emitLedgerEvent(
            'project.agent.invoked',
            payload: $failurePayload + ['response_length' => strlen($response)],
            durationMs: $durationMs,
        );

        $reply = new PostProjectMessageAction(
            project: $this->project,
            verb: 'project-agent-reply',
            content: $response,
            author: $agent->user,
            fromIa: true,
            parentMessageId: $this->triggerMessageId,
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

        return $this->firstOrCreateWakeSession(
            $this->project,
            create: [
                'agents_id' => $this->project->agent_id,
                'channel_id' => $this->project->default_channel_id,
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
        );
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

        // Cap the trigger text — a mention/ingest could quote a huge prior message; never let it
        // balloon the prompt (belt-and-braces with persistConversation:false).
        $trigger = $this->triggerMessage !== null && $this->triggerMessage !== ''
            ? "New context on the project:\n\"\"\"\n" . Str::limit($this->triggerMessage, 4000) . "\n\"\"\"\n\n"
            : '';

        return $header
            . 'You are the PM of this project. Read the context below, decide what needs to happen, '
            . "then use your tools to create/assign/move tasks and keep the project moving.\n\n"
            . $trigger
            . 'Context: ' . (string) json_encode($bundle->toArray());
    }
}
