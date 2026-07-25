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
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

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
            channel: $this->resolveReplyChannel(),
        )->execute();

        $this->project->emitLedgerEvent(
            'project.agent.replied',
            payload: [
                'agent_id' => $this->project->agent_id,
                'message_id' => $reply->getId(),
            ],
        );
    }

    /**
     * A @mention must be answered on the SAME channel it came from — the plan's activity thread, the
     * project channel, wherever the person asked — otherwise the reply lands on the project's default
     * channel and they never see it. Only mentions carry a specific originating thread; every other
     * wake (ingest, heartbeat, assigned) posts to the default channel as before.
     */
    private function resolveReplyChannel(): ?Channel
    {
        if ($this->reason !== self::REASON_MENTION || $this->triggerMessageId === null) {
            return null;
        }

        $message = Message::query()->where('id', $this->triggerMessageId)->first();

        /** @var Channel|null $channel */
        $channel = $message?->channels()->first();

        return $channel;
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

        $trigger = '';
        if ($this->triggerMessage !== null && $this->triggerMessage !== '') {
            // Ingested transcripts/emails are far larger than any prompt should hold. Hand the PM a short
            // preview plus the message id and make it pull the FULL content with read_message_content — so
            // it decomposes the whole thing, not just the opening (the old 4000-char truncation is exactly
            // why plans came out thin on long meetings).
            $isIngest = $this->reason === self::REASON_INGEST;
            $preview = Str::limit($this->triggerMessage, $isIngest ? 1500 : 4000);
            $label = $this->triggerMessageId !== null
                ? "New context on the project (message #{$this->triggerMessageId})"
                : 'New context on the project';
            $readFull = $isIngest && $this->triggerMessageId !== null
                ? "\nThis is only a PREVIEW. Call read_message_content(message_id={$this->triggerMessageId}) and "
                    . 'page with next_offset until has_more is false to read the ENTIRE content, then decompose '
                    . 'all of it — not just this preview.'
                : '';
            $trigger = "{$label}:\n\"\"\"\n{$preview}\n\"\"\"{$readFull}\n\n";
        }

        return $header
            . 'You are the PM of this project. Read the context below, decide what needs to happen, '
            . "then use your tools to create/assign/move tasks and keep the project moving.\n\n"
            . $trigger
            . 'Context: ' . (string) json_encode($bundle->toArray());
    }
}
