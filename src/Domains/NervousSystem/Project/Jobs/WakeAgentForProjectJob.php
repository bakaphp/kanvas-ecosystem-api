<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
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
    use DrivesAgentWake;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON_INGEST = 'ingest';
    public const string REASON_HEARTBEAT = 'heartbeat';
    public const string REASON_ASSIGNED = 'assigned';
    public const string REASON_MENTION = 'mention';

    /** A plan this PM delegated reached a terminal state — the delegation loop closing. */
    public const string REASON_PLAN_OUTCOME = 'plan_outcome';
    public const string NO_UPDATE_SENTINEL = 'NO_UPDATE';
    private const int WAKE_LOCK_TTL_SECONDS = 600;
    private const int MENTION_RETRY_SECONDS = 15;

    /** WAKE_LOCK_TTL_SECONDS / MENTION_RETRY_SECONDS + the first attempt: a mention outlasts any held lock. */
    private const int MENTION_MAX_ATTEMPTS = 41;

    // Space out exception-driven retries so a genuinely-failing wake doesn't hammer the LLM/ledger.
    // (WithoutOverlapping's releaseAfter for a mention lock-collision sets its own delay independently.)
    public int $backoff = 30;

    /**
     * Bound retries by ATTEMPTS, never wall-clock: a `retryUntil` deadline is set at dispatch and checked
     * at pickup, so queue WAIT counts against it — and every job on this queue is a multi-second LLM turn
     * serialized per project, so a wake behind a backlog dies without running.
     *
     * The mention budget is sized for lock COLLISIONS (see {@see self::middleware()}); `maxExceptions` is
     * what keeps it from becoming 41 LLM calls when the wake itself is what's failing.
     */
    public int $tries = 3;
    public int $maxExceptions = 3;

    /**
     * `wakeAgent` overrides the project's current PM. A plan outcome goes to whoever ASKED for the
     * work (`Plan::createdByAgent`), which is a different agent once a project has changed hands —
     * re-deriving `pmAgent` here would wake someone who never delegated it.
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $reason,
        public readonly ?string $triggerMessage = null,
        public readonly ?int $triggerMessageId = null,
        public readonly ?Agent $wakeAgent = null,
    ) {
        $this->onQueue('nervous-system-project');

        if ($this->reason === self::REASON_MENTION) {
            $this->tries = self::MENTION_MAX_ATTEMPTS;
        }
    }

    private function agentToWake(): ?Agent
    {
        return $this->wakeAgent ?? $this->project->pmAgent;
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

        $agent = $this->agentToWake();
        $owner = $this->project->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        $session = $this->resolveSession();
        $failurePayload = [
            'agent_id' => $agent->getId(),
            'session_id' => $session->getId(),
            'reason' => $this->reason,
        ];

        // Baseline the channel the reply would land on, so we can tell afterwards whether the PM
        // already said it there with comment_on_nervous_system_plan.
        $replyChannel = $this->resolveReplyChannel();
        $activityBefore = $this->latestMessageId($replyChannel);

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

        if ($this->isNoOpResponse($response)) {
            $this->project->emitLedgerEvent(
                'project.agent.noop',
                payload: $failurePayload,
                durationMs: $durationMs,
            );

            return;
        }

        // The PM has a board tool now, so it can answer by commenting on the plan and then be handed
        // the floor again to "reply" — posting the same thing twice, seconds apart, the second one
        // narrating the first ("Actions Taken: commented on the thread..."). One turn is one message.
        if ($this->agentPostedDuringRun($replyChannel, $activityBefore, $agent->user)) {
            $this->project->emitLedgerEvent(
                'project.agent.reply_skipped',
                payload: $failurePayload + ['channel_id' => $replyChannel?->getId()],
                durationMs: $durationMs,
            );

            return;
        }

        $reply = new PostProjectMessageAction(
            project: $this->project,
            verb: 'project-agent-reply',
            content: $response,
            author: $agent->user,
            fromIa: true,
            parentMessageId: $this->triggerMessageId,
            channel: $replyChannel,
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
     * The wake is being abandoned — retries exhausted, or a mention that never won the lock. Record it on
     * the project, because the queue's failure record carries no project context and an abandoned mention
     * means a human asked the PM something and will never get an answer.
     *
     * `Job::fail()` does not guard this call, so a throw here REPLACES the failure being recorded and
     * escapes the worker's own handling of it. The ledger write is the thing most likely to fail for the
     * same reason the wake did (the DB), so it must never take the real exception down with it.
     */
    public function failed(Throwable $exception): void
    {
        try {
            $this->overwriteAppService($this->project->app);

            $this->project->emitLedgerEvent(
                'project.agent.wake_abandoned',
                status: EventStatusEnum::ERROR,
                payload: [
                    'reason' => $this->reason,
                    'attempts' => $this->attempts(),
                    'trigger_message_id' => $this->triggerMessageId,
                ],
                error: ['message' => $exception->getMessage(), 'class' => $exception::class],
            );
        } catch (Throwable) {
        }
    }

    /**
     * The PM decided there's nothing new to post — an empty turn, or the NO_UPDATE sentinel (allowing
     * for the model wrapping it in markdown/punctuation). Suppressing these is what makes the heartbeat
     * quiet when nothing changed.
     */
    private function isNoOpResponse(string $response): bool
    {
        return AgentTurnResponse::isNoOp($response);
    }

    /**
     * The channel this turn answers on.
     *
     * A @mention must be answered on the SAME channel it came from — the plan's activity thread, the
     * project channel, wherever the person asked — otherwise the reply lands on the project's default
     * channel and they never see it. Only mentions carry a specific originating thread; every other
     * wake (ingest, heartbeat, assigned, plan outcome) answers on the default channel.
     *
     * Null only when the project has no channel bound yet — `PostProjectMessageAction` binds one at
     * post time, which is deliberately NOT done here: resolving a target must not create one.
     */
    private function resolveReplyChannel(): ?Channel
    {
        if ($this->reason !== self::REASON_MENTION || $this->triggerMessageId === null) {
            return $this->project->defaultChannel;
        }

        $message = Message::query()->where('id', $this->triggerMessageId)->first();

        /** @var Channel|null $channel */
        $channel = $message?->channels()->first();

        return $channel ?? $this->project->defaultChannel;
    }

    /**
     * Keyed per project, and additionally per agent when someone other than the project's PM is
     * woken — otherwise a delegating agent inherits the PM's thread and answers out of its history.
     * The PM's own session keeps its original key so existing threads are not forked.
     */
    protected function resolveSession(): Session
    {
        $agent = $this->agentToWake();
        $owner = $this->project->user ?? $agent?->user;
        $isProjectPm = $agent === null || $agent->getId() === $this->project->agent_id;

        return $this->firstOrCreateWakeSession(
            $this->project,
            create: [
                'agents_id' => $agent?->getId() ?? $this->project->agent_id,
                'channel_id' => $this->project->default_channel_id,
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
            extraKey: $isProjectPm ? [] : ['agents_id' => $agent->getId()],
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
            . $this->noUpdateGuidance()
            . $trigger
            . 'Context: ' . (string) json_encode($bundle->toArray());
    }

    /**
     * Automated wakes (heartbeat especially) fire on a timer, not because something happened — so the
     * PM must be free to say nothing. Without this it dumps a near-identical status update every tick.
     */
    private function noUpdateGuidance(): string
    {
        if ($this->reason !== self::REASON_HEARTBEAT && $this->reason !== self::REASON_ASSIGNED) {
            return '';
        }

        return 'This is a periodic check-in, not a new event. Look at the recent messages/events below: '
            . 'if NOTHING has changed since your last status update — no new messages, no task/plan '
            . 'status changes, nothing a human needs — then DO NOT post another update. Reply with '
            . 'exactly ' . self::NO_UPDATE_SENTINEL . ' and nothing else. Only post when there is a real '
            . "change to report, a real ask, or work to move. Do not repeat what you already said.\n\n";
    }
}
