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
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Services\BlockOutcomeDeliveryService;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
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
    use AnnouncesPlanOutcome;
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

    /** A worker's report is one read_plan_activity call away; the prompt carries enough to decide. */
    private const int WORKER_SUMMARY_CHARS = 1500;
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
     * what keeps it from becoming 41 LLM calls when the wake itself is what's failing. A wake deferred
     * behind a busy thread is a release, and a release counts as an attempt, so the default leaves room
     * for a few of those on top of the three exceptions.
     */
    public int $tries = 6;
    public int $maxExceptions = 3;

    private ?Message $mention = null;

    /**
     * `wakeAgent` overrides the project's current PM. A plan outcome goes to whoever ASKED for the
     * work (`Plan::createdByAgent`), which is a different agent once a project has changed hands —
     * re-deriving `pmAgent` here would wake someone who never delegated it.
     *
     * `mentionMessageId` is the message whose files ride along, resolved when the job RUNS: the
     * comment UI uploads attachments in a second request, so at dispatch they may not exist yet. It is
     * not `triggerMessageId`, which for a threaded mention is the thread root the reply goes under.
     *
     * `reportsOn` is the plan whose finish this wake reports. The person who asked for it is in the
     * conversation they asked in, not on the project channel, so the reply goes there as well.
     *
     * `aboutPlan` is the plan any outcome concerns, finished or not. `reportsOn` cannot carry that: a
     * blocked, failed or rejected plan has no finish to report, and without the plan such a wake would
     * be about the whole board.
     *
     * `blockOutcomeId` is the block cycle this wake answers ({@see BlockOutcomeDeliveryService}); the
     * blocked alert queued for the same cycle waits for it and speaks only if this wake does not.
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $reason,
        public readonly ?string $triggerMessage = null,
        public readonly ?int $triggerMessageId = null,
        public readonly ?Agent $wakeAgent = null,
        public readonly ?int $mentionMessageId = null,
        public readonly ?Plan $reportsOn = null,
        public readonly ?Plan $aboutPlan = null,
        public readonly ?string $blockOutcomeId = null,
    ) {
        $this->onQueue('nervous-system-project');

        if ($this->waitsForTheLock()) {
            $this->tries = self::MENTION_MAX_ATTEMPTS;
        }
    }

    /**
     * A person is waiting on this wake's reply, so a collision re-queues it instead of dropping it: a
     * direct @mention, or the report of a finished plan, whose done alert in the person's conversation
     * is held back for this answer (`Plan::rememberAnswerIsComing()`).
     */
    private function waitsForTheLock(): bool
    {
        return $this->reason === self::REASON_MENTION
            || $this->reportsOn !== null
            || $this->isDelegatedOutcome();
    }

    private function subjectPlan(): ?Plan
    {
        return $this->aboutPlan ?? $this->reportsOn;
    }

    /**
     * An outcome of work the woken agent handed off, as opposed to a PM hearing about its own board.
     * It is about one plan and one person, so it gets a lock, a session, a prompt and an identity of
     * its own: on a board a whole company delegates through, sharing any of them makes one person's
     * answer wait on, or leak into, another's.
     */
    private function isDelegatedOutcome(): bool
    {
        $agent = $this->subjectPlan() !== null ? $this->agentToWake() : null;

        return $agent !== null && $this->project->isHandedOffBy($agent);
    }

    private function agentToWake(): ?Agent
    {
        return $this->wakeAgent ?? $this->project->pmAgent;
    }

    /**
     * Serialize wakes per project: two concurrent PM turns waste an LLM call and can race.
     *
     * Automated wakes (ingest/heartbeat/assigned) DROP on collision — the in-flight wake already reads
     * fresh state and the heartbeat mops up anything mid-run. A wake a person is waiting on
     * ({@see self::waitsForTheLock()}) must never be silently dropped: on collision it re-queues and
     * answers as soon as the in-flight turn frees the lock. The lock TTL guards against a crashed holder
     * wedging the project.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $key = $this->isDelegatedOutcome()
            ? 'plan-wake-' . $this->subjectPlan()?->getId()
            : 'project-wake-' . $this->project->getId();

        $lock = new WithoutOverlapping($key)->expireAfter(self::WAKE_LOCK_TTL_SECONDS);

        return [
            $this->waitsForTheLock()
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

        // Before the session or the agent exists: a request nobody can be shown to have made is not
        // run at all, on anyone's rights.
        $identity = $this->turnIdentity($agent, $owner);

        if ($identity === null) {
            $this->project->emitLedgerEvent(
                'project.agent.mention_without_author',
                status: EventStatusEnum::ERROR,
                payload: [
                    'agent_id' => $agent->getId(),
                    'mention_message_id' => $this->mentionMessageId,
                ],
            );

            return;
        }

        [$turnUser, $human, $unattended] = $identity;

        // A retry of a wake whose block was already answered, by this job or by the fallback, or whose
        // plan has since moved on or blocked again: nothing left for it to say.
        if ($this->blockIsNoLongerOursToAnswer()) {
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
        ['images' => $images, 'documents' => $documents] = $this->mentionMedia();

        $wake = $this->runAgentWake(
            $agent,
            $session,
            $turnUser,
            $this->buildMessage(),
            $this->project,
            'project.agent',
            $failurePayload,
            images: $images,
            documents: $documents,
            unattended: $unattended,
            conversationHuman: $human,
        );

        // Null means the job was put back behind a busy thread, not that it failed: a block reservation
        // stays, or the fallback would speak now and this wake again on its retry.
        if ($wake === null) {
            $this->reportsOn?->forgetAnswerIsComing();

            return;
        }

        [$response, $durationMs] = $wake;

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
            $this->finishAnswerPlan()?->forgetAnswerIsComing();
            $this->letTheBlockedAlertSpeak();

            return;
        }

        $this->answerWhereThePlanWasAskedFor($agent, $response);

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
     * Only in the voice of the agent that asked for the plan: the conversation is between the person
     * and that agent, and the reply is its answer to them. Best-effort — the project channel keeps it.
     */
    private function answerWhereThePlanWasAskedFor(Agent $agent, string $response): void
    {
        // A finish always; any other outcome only for work that was handed off, where nobody else
        // would tell the person.
        $plan = $this->reportsOn ?? ($this->isDelegatedOutcome() ? $this->aboutPlan : null);

        if ($plan === null || ! $agent->is($plan->createdByAgent)) {
            return;
        }

        $post = fn (): bool => $this->alsoPostToOriginConversation(
            $plan,
            $response,
            'project-agent-reply',
            mention: $plan->askedInASharedConversation(),
        ) !== null;

        try {
            $told = $this->blockOutcomeId !== null
                ? new BlockOutcomeDeliveryService($plan)
                    ->claimDelivery($this->blockOutcomeId, asCreator: true, deliver: $post)
                : $post();
        } catch (Throwable $e) {
            $told = false;
            report($e);
        }

        // Nothing reached the person (no conversation to post in, the write failed, or the fallback
        // already spoke), so the alerts that stood down for this answer are free again.
        if (! $told) {
            $this->finishAnswerPlan()?->forgetAnswerIsComing();
            $this->letTheBlockedAlertSpeak();
        }
    }

    /**
     * The plan whose done alert was held back for this wake's answer: a reported finish, or a handed-off
     * plan closed directly.
     */
    private function finishAnswerPlan(): ?Plan
    {
        if ($this->reportsOn !== null) {
            return $this->reportsOn;
        }

        $plan = $this->isDelegatedOutcome() ? $this->aboutPlan : null;

        return $plan?->status === PlanStatusEnum::DONE->value ? $plan : null;
    }

    private function blockIsNoLongerOursToAnswer(): bool
    {
        $plan = $this->subjectPlan();

        if ($this->blockOutcomeId === null || $plan === null) {
            return false;
        }

        return ! new BlockOutcomeDeliveryService($plan)->isAwaitingAnAnswer($this->blockOutcomeId);
    }

    /**
     * The handing-off agent did not say the plan is blocked after all: it had nothing to say, its
     * answer could not be posted, or its wake was abandoned. The reservation it held is released and
     * the fallback runs now. Both are no-ops for a cycle that is no longer current.
     */
    private function letTheBlockedAlertSpeak(): void
    {
        $plan = $this->subjectPlan();

        if ($this->blockOutcomeId === null || $plan === null) {
            return;
        }

        $delivery = new BlockOutcomeDeliveryService($plan);

        if (! $delivery->isAwaitingAnAnswer($this->blockOutcomeId)) {
            return;
        }

        $delivery->releaseReservation($this->blockOutcomeId);
        NotifyPlanOwnerOfBlockedPlanJob::dispatch($plan, $this->blockOutcomeId);
    }

    /**
     * The wake is being abandoned — retries exhausted, or a mention that never won the lock. Record it on
     * the project, because the queue's failure record carries no project context and an abandoned mention
     * means a human asked the PM something and will never get an answer.
     *
     * `Job::fail()` does not guard this call, so a throw here REPLACES the failure being recorded and
     * escapes the worker's own handling of it. The ledger write is the thing most likely to fail for the
     * same reason the wake did (the DB), so it must never take the real exception down with it.
     *
     * The plan it was reporting on is released first: its creator never heard the finish and never
     * answered, so the done alert and the plan's closing wake have to go out after all.
     */
    public function failed(Throwable $exception): void
    {
        try {
            $this->reportsOn?->forgetFinishWasReported();
            $this->finishAnswerPlan()?->forgetAnswerIsComing();
            $this->letTheBlockedAlertSpeak();

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
        $plan = $this->subjectPlan();

        // One thread per handed-off plan: a board-wide thread makes two people's outcomes wait on each
        // other, and puts one person's work in the history another's answer is written from.
        if ($agent !== null && $plan !== null && $this->isDelegatedOutcome()) {
            return Session::firstOrCreateForEntity(
                $plan,
                create: [
                    'agents_id' => $agent->getId(),
                    'channel_id' => $this->project->default_channel_id,
                    'user' => $this->sessionUser($plan->asker() ?? $owner),
                ],
                extraKey: ['agents_id' => $agent->getId()],
            );
        }

        $isProjectPm = $agent === null || $agent->getId() === $this->project->agent_id;

        return Session::firstOrCreateForEntity(
            $this->project,
            create: [
                'agents_id' => $agent?->getId() ?? $this->project->agent_id,
                'channel_id' => $this->project->default_channel_id,
                'user' => $this->sessionUser($owner),
            ],
            extraKey: $isProjectPm ? [] : ['agents_id' => $agent->getId()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionUser(?Users $user): array
    {
        return $user !== null ? [
            'id' => $user->getId(),
            'name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
            'email' => $user->email ?? null,
        ] : [];
    }

    protected function buildMessage(): string
    {
        $header = sprintf(
            "[NS:project reason=%s project_id=%d project_uuid=%s]\n\n",
            $this->reason,
            $this->project->getId(),
            $this->project->uuid,
        );

        $plan = $this->subjectPlan();
        if ($plan !== null && $this->isDelegatedOutcome()) {
            return $header . $this->delegatedOutcomeMessage($plan);
        }

        $bundle = new ProjectContextService()->buildContextBundle($this->project, historyLimit: 20);

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

        // media carries the bytes; the markers carry the id the file tools key on. Both, as the
        // generic mention job does — without them the PM answers "no attachments" to the notes.
        $trigger = AttachmentPromptBuilder::withFilesystemMarkers(
            $trigger,
            $this->mentionMessage()?->files ?? [],
        );

        return $header
            . ($this->project->isCompanyOperations()
                ? 'This is your Operations board: the work you handed to other agents. Check what is stalled '
                    . "or blocked and follow through.\n\n"
                : 'You are the PM of this project. Read the context below, decide what needs to happen, '
                    . "then use your tools to create/assign/move tasks and keep the project moving.\n\n")
            . $this->noUpdateGuidance()
            . $trigger
            . 'Context: ' . (string) json_encode($bundle->toArray());
    }

    /**
     * Who this turn runs as, the person who typed it, and whether nobody is there. Null when the turn
     * must not run.
     *
     * A mention is the only wake a person typed, and it is authorised as that person. With no author
     * there is nobody to authorise it as: the project's owner did not ask, and the agent's own user can
     * itself be an administrator or hold the hire ability, so neither is a safe stand-in.
     *
     * @return array{0: Users, 1: ?Users, 2: bool}|null
     */
    private function turnIdentity(Agent $agent, Users $owner): ?array
    {
        if ($this->isDelegatedOutcome()) {
            return [$this->subjectPlan()?->asker() ?? $owner, null, true];
        }

        if ($this->reason !== self::REASON_MENTION) {
            return [$owner, null, true];
        }

        $author = $this->mentionAuthor($agent);

        return $author !== null ? [$owner, $author, false] : null;
    }

    /**
     * Read off the live mention itself and nothing else: `triggerMessageId` is the thread's root, whose
     * author is whoever opened the thread.
     */
    private function mentionAuthor(Agent $agent): ?Users
    {
        $mention = $this->mentionMessage();

        if ($mention === null || $mention->users_id === null) {
            return null;
        }

        try {
            $author = Users::getById((int) $mention->users_id);
        } catch (Throwable) {
            return null;
        }

        return KanvasConversationStore::actsAsAi($author, $agent) ? null : $author;
    }

    /**
     * That plan and nothing else. The board holds every employee's requests; handing the whole bundle
     * to a turn that answers one of them puts the others' work into that answer.
     */
    private function delegatedOutcomeMessage(Plan $plan): string
    {
        $tasks = $plan->tasks()
            ->whereNull('parent_task_id')
            ->orderBy('sequence')
            ->get()
            ->map(fn (Task $task): array => array_filter([
                'task_id' => $task->getId(),
                'title' => $task->title,
                'status' => $task->status,
                'blocked_reason' => $task->blocked_reason,
                'worker_summary' => Str::limit((string) $task->workerSummary(), self::WORKER_SUMMARY_CHARS),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->all();

        $summary = array_filter([
            'plan_id' => $plan->getId(),
            'title' => $plan->title,
            'status' => $plan->status,
            'blocked_needs' => $plan->blocked_needs,
            'error' => $plan->error_message,
            'assignee' => $plan->agent?->name,
            'tasks' => $tasks,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        $fact = $this->triggerMessage !== null && $this->triggerMessage !== ''
            ? $this->triggerMessage . "\n\n"
            : '';

        // A finish is always news to the person. Anything else is news only when it cannot be put right.
        $unfinished = $this->reportsOn === null
            ? 'If you can fix it by reassigning, do that and reply ' . self::NO_UPDATE_SENTINEL . '. Otherwise tell the '
                . "person plainly what is stuck and what is needed, including when it needs an admin.\n\n"
            : '';

        return "You handed this work to another agent. It has an outcome to report.\n\n"
            . $fact
            . $unfinished
            . 'Plan: ' . (string) json_encode($summary);
    }

    private function mentionMessage(): ?Message
    {
        if ($this->mentionMessageId === null) {
            return null;
        }

        return $this->mention ??= Message::query()
            ->where('id', $this->mentionMessageId)
            ->fromApp($this->project->app)
            ->fromCompany($this->project->company)
            ->notDeleted()
            ->first();
    }

    /**
     * @return array{images: list<string>, documents: list<string>}
     */
    private function mentionMedia(): array
    {
        return $this->mentionMessage()?->attachmentUrls() ?? ['images' => [], 'documents' => []];
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
