<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\OpenCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryStage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CodingChatCheckouts;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * One worker, one task.
 *
 * The unit that makes a plan parallel: several of these run at once for tasks that do not depend on
 * each other, and the batch's completion wakes the orchestrator through the edge that already exists.
 *
 * Three things keep it a worker rather than a second orchestrator:
 *  - it runs inside `WorkerToolPolicy`, so board mutation, delegation, outbound and scheduling are
 *    not in its toolset at all;
 *  - it gets its own Session, so its reasoning never enters the orchestrator's context — the parent
 *    sees the task's status and result, not the transcript that produced them;
 *  - it cannot spawn another worker, because dispatch lives on the orchestrator's side of the policy.
 *
 * `tries = 1` matches `ProcessAgentChatTurnJob`. A retried worker would repeat whatever side effects
 * the first attempt already committed, and tool idempotency is a separate programme.
 */
class RunTaskWorkerJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Task $task,
        /**
         * Set when the worker is being resumed rather than started: the outcome of work it delegated.
         * It replaces the brief, because the task's own thread already holds the brief.
         */
        public readonly ?string $resumeFact = null,
    ) {
        $this->onQueue('agent-task-worker');
    }

    public function handle(): void
    {
        // Bouncer scope and the container-bound app are process-global on a long-running worker, so a
        // batch spanning tenants has to rebind per job or the second inherits the first's scope.
        $this->overwriteAppService($this->task->plan->app);

        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $plan = $this->task->plan;
        $agent = $this->task->agent ?? $plan->agent;
        $owner = $plan->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        if (! $this->planAllowsWork($plan)) {
            return;
        }

        $this->markInProgress();

        $startedAt = microtime(true);

        try {
            $response = CodingChatSettingEnum::runsTaskInFactory($agent, $this->task->fullText())
                ? $this->workInTheFactory($agent, $owner)
                : WorkerToolPolicy::within(fn (): string => new AgentChatKernel(
                    agent: $agent,
                    session: $this->workerSession(),
                    message: $this->resumeFact !== null ? $this->resumeBrief() : $this->brief(),
                    user: $owner,
                    persistConversation: false,
                    // A worker runs as the plan's owner, who is not there.
                    unattended: true,
                )->execute());
        } catch (Throwable $e) {
            $this->markBlocked($e->getMessage());

            $this->task->emitLedgerEvent(
                eventType: 'plan.task.worker_failed',
                status: EventStatusEnum::ERROR,
                payload: ['task_id' => $this->task->getId(), 'plan_id' => $plan->getId()],
                error: ['message' => $e->getMessage(), 'class' => $e::class],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000.0),
            );

            // Swallowed deliberately: one failed task blocks itself and the batch carries on. Letting
            // it throw would fail the batch and strand tasks that had nothing to do with this one.
            $this->wakeOrchestratorIfUnbatched();

            return;
        }

        // A worker that handed part of its task to a coding job has not finished it — the job's
        // outcome resumes this worker. Recorded as done, the task closed on plan 725 while the real
        // work was still running, and nothing came back when it landed.
        $openChildren = $this->task->children()->open()->get(['id', 'title']);

        if ($openChildren->isNotEmpty()) {
            $this->markWaitingOnChildren($response, $openChildren->pluck('title')->all());

            $this->task->emitLedgerEvent('plan.task.worker_delegated', payload: [
                'task_id' => $this->task->getId(),
                'plan_id' => $plan->getId(),
                'children' => $openChildren->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ], durationMs: (int) ((microtime(true) - $startedAt) * 1000.0));

            return;
        }

        // A worker that says it could not do the work must not close its own task. The people-audit
        // task returned "### Task Status Report: Blocked — the health report does not expose that
        // metric" and was still marked done; the orchestrator then reported the count as 0, because a
        // done task with no contradiction looks like an answer. Marking it done is a claim, and the
        // only evidence for it is the text the worker just wrote.
        if (TaskDirectives::reportsItselfBlocked($response)) {
            $this->markBlocked($response, keepAsResult: true);

            $this->task->emitLedgerEvent(
                eventType: 'plan.task.worker_self_blocked',
                status: EventStatusEnum::ERROR,
                payload: [
                    'task_id' => $this->task->getId(),
                    'plan_id' => $plan->getId(),
                ],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000.0),
            );

            $this->wakeOrchestratorIfUnbatched();

            return;
        }

        $this->markDone($response);

        $this->task->emitLedgerEvent(
            'plan.task.worker_completed',
            payload: [
                'task_id' => $this->task->getId(),
                'plan_id' => $plan->getId(),
                'response_length' => strlen($response),
            ],
            durationMs: (int) ((microtime(true) - $startedAt) * 1000.0),
        );

        $this->startNextInLine();
        $this->wakeOrchestratorIfUnbatched();
    }

    /**
     * A factory task that was assigned while this one ran has been waiting for it (`WakeAgentForTaskJob`);
     * it starts now that this one is done. Only on done: a blocked task stops the line for the
     * orchestrator to decide.
     */
    private function startNextInLine(): void
    {
        $next = $this->task->nextInLine();
        $agent = $next?->agent;

        if ($next === null || $agent === null) {
            return;
        }

        // A Kanvas step after a factory step was held for it (WakeAgentForTaskJob), so it starts here too.
        if (! $this->task->runsInFactory() && ! CodingChatSettingEnum::runsTaskInFactory($agent, $next->fullText())) {
            return;
        }

        WakeAgentForTaskJob::dispatch($next);
    }

    /**
     * A band's batch wakes the orchestrator once for all its workers. A resumed worker runs alone, so
     * it has to say it finished itself — unless it is somebody's child, in which case its own
     * outcome row already resumes that parent.
     */
    private function wakeOrchestratorIfUnbatched(): void
    {
        if ($this->batch() !== null || $this->task->parent_task_id !== null) {
            return;
        }

        WakeAgentForPlanJob::dispatch(
            $this->task->plan,
            WakeAgentForPlanJob::REASON_TASK_COMPLETED,
            sprintf('Task #%d ("%s") is now %s.', $this->task->getId(), (string) $this->task->title, (string) $this->task->status),
        );
    }

    /**
     * An agent in factory mode has no model of its own here: the opencode agent in the factory checkout
     * does the task. Started, the run becomes this task's child, so the flow below parks the task as
     * waiting on it. Resumed, the run has ended and its answer IS the result — running the factory
     * again would start the same work twice, so the answer goes straight into the same done/blocked
     * decision a model worker's reply does.
     */
    private function workInTheFactory(Agent $agent, Users $owner): string
    {
        if ($this->resumeFact !== null) {
            return $this->factoryAnswer();
        }

        $repo = new CodingChatCheckouts($agent)->forTask($this->task->fullText())
            ?? CodingChatSettingEnum::defaultRepoFor($agent);

        // Read before anything opens: a branch the task cannot have blocks it here, with the reason.
        TaskDirectives::branch($this->task->fullText());

        if ($repo === null) {
            throw new ValidationException(
                'No checkout to work in: name the app in the task (for example "Repo: example-app"), '
                . 'or set CODING_CHAT_DEFAULT_REPO on ' . $agent->name . '.'
            );
        }

        // Two assigns can queue two of these jobs before either has created the run, and two runs would
        // work one ticket on one branch. Under the lock the second sees the first's run and waits on it.
        return Cache::lock('factory-run:task-' . $this->task->getId(), 120)->block(
            30,
            function () use ($agent, $owner, $repo): string {
                if ($this->task->children()->open()->exists()) {
                    return 'Already in the factory.';
                }

                new OpenCodingChatAction(
                    agent: $agent,
                    chat: $this->workerSession(),
                    repo: $repo,
                    instruction: $this->factoryBrief(),
                    requestedBy: $owner,
                    parentTask: $this->task,
                )->execute();

                return 'Handed to the factory: working in ' . $repo . '.';
            }
        );
    }

    /**
     * The run's own words when it finished, else why it did not. A run that did not end DONE is
     * reported as BLOCKED whatever it said, so its last cheerful line cannot close the task as done.
     */
    private function factoryAnswer(): string
    {
        /** @var Task|null $run */
        $run = $this->task->children()->latest('id')->first();
        $result = is_array($run?->result) ? $run->result : [];
        $said = Str::trimToNull(is_string($result['worker_summary'] ?? null) ? $result['worker_summary'] : null)
            ?? (string) $this->resumeFact;

        if ($run?->status === TaskStatusEnum::DONE->value) {
            return $said;
        }

        return 'BLOCKED — ' . (Str::trimToNull((string) $run?->blocked_reason) ?? $said);
    }

    /**
     * The brief for a coding agent that has none of the board tools `brief()` talks about, and nobody
     * to ask: everything it needs to finish or to say clearly that it could not.
     */
    private function factoryBrief(): string
    {
        $plan = $this->task->plan;

        $sharedBranch = TaskDirectives::branch($this->task->fullText()) !== null;

        return implode("\n\n", array_filter([
            'You are working ONE task of the plan "' . $plan->title . '", handed to you by '
            . ($plan->agent?->name ?? 'the team') . '. You are working unattended: nobody will answer a '
            . 'question, so do not ask one. The task\'s instructions count as the person\'s request.',
            'Task: ' . $this->task->title,
            Str::trimToNull((string) $this->task->description) !== null ? 'Detail: ' . $this->task->description : null,
            // Every factory task, whoever wrote it: the repository's own rules decide how a change is made and
            // proven, and a pull request opened on work nobody ran is the failure this prevents.
            'Before you change anything, read the repository\'s `CLAUDE.md` and its `specs/` (or `docs/`) and '
            . 'follow them. Before any pull request, run every check, test and QA step they require, and for a '
            . 'mobile app run your change on both iOS and Android. Never open a pull request for work you have not '
            . 'run.',
            $sharedBranch
                ? 'You are in a worktree of your own, on the branch the task names. Other tasks of the same '
                    . 'ticket work on it too: build on the commits already there and change nothing they did '
                    . 'unless the task asks you to.'
                : 'You are in a worktree of your own, on a branch made for this task from the app\'s base branch '
                    . '(usually develop).',
            // On a shared branch "end to end" with push as the default reads as the whole ticket.
            $sharedBranch
                ? 'Do only what this task asks: the ticket\'s other steps come after you on this branch. Commit '
                    . 'your work with Conventional Commit messages. Push the branch and open a pull request only '
                    . 'if this task tells you to.'
                : 'Finish the task end to end: commit your work with Conventional Commit messages, and once the '
                    . 'checks pass, push the branch and open a draft pull request into the app\'s base branch, '
                    . 'unless the task says otherwise.',
            'A task that asks you to check, verify, audit, review or report is answered in your reply, and '
            . 'that reply is the whole deliverable: change nothing, commit nothing, open no pull request. A '
            . 'report is never a file in the repository.',
            'When you finish, reply with what you did and where it is: branch, commits, pull request link, '
            . 'reports. IF YOU COULD NOT DO IT, START YOUR REPLY WITH THE WORD BLOCKED, then say exactly '
            . 'what stopped you and what someone has to do.',
            'Your run ends when you reply, so do every step before you reply, and end that final reply with a '
            . 'line that says exactly ' . AgentTaskSession::RUN_FINISHED_MARKER . '. A reply without it is read '
            . 'as stopping midway, and you are asked to carry on.',
        ]));
    }

    private function resumeBrief(): string
    {
        return sprintf(
            "[NS:task_worker] task_id=%d plan_id=%d resumed=1\n\n"
            . "You are resuming the ONE task you were working; its brief is above in this thread.\n\n"
            . "What just happened: %s\n\n"
            . 'Finish the task from here. If the delegated work completes it, report the result — what was '
            . 'delivered and where (branch, pull request, file). If it did not, say what is still missing. '
            . 'IF YOU CANNOT FINISH, START YOUR ANSWER WITH THE WORD BLOCKED, then say exactly what stopped you. '
            . 'Do not start the same delegated work again.',
            $this->task->getId(),
            $this->task->plan->getId(),
            $this->resumeFact,
        );
    }

    /**
     * The worker's own conversation, keyed on the task. Separate from the plan's session on purpose —
     * it is what keeps a worker's intermediate reasoning out of the orchestrator's context.
     */
    private function workerSession(): Session
    {
        return Session::firstOrCreateForEntity($this->task, create: ['agents_id' => $this->task->agent_id ?? $this->task->plan->agent_id]);
    }

    private function brief(): string
    {
        return sprintf(
            "[NS:task_worker] task_id=%d plan_id=%d\n\n"
            . "You are working ONE task and nothing else.\n\n"
            . "Plan objective: %s\nYour task: %s\n%s\n"
            . 'Do the work and report what you did. You cannot change the plan, assign anything, message '
            . 'anyone, or start other work — those tools are deliberately not available to you.'
            . "\n\nIF YOU COULD NOT DO IT, START YOUR ANSWER WITH THE WORD BLOCKED, then say exactly what "
            . 'stopped you. That first word is what marks the task blocked instead of done — without it '
            . "your report reads as a result, and whoever asked is told the work finished.\n"
            . 'When what blocks you is a MISSING TOOL rather than missing information — no tool can read '
            . 'that field, filter that way, or reach that system — do not stop at saying so. Run '
            . 'capability_lookup on what you needed, and if nothing covers it call report_capability_gap '
            . 'with the specifics. A blocked_reason is read by whoever opens this task; a capability gap '
            . 'is read by whoever decides what gets built. Yours is the only account of what was actually '
            . 'missing, so it is worth more than a note on a task nobody reopens.',
            $this->task->getId(),
            $this->task->plan->getId(),
            $this->task->plan->title,
            $this->task->title,
            $this->task->description !== null && $this->task->description !== ''
                ? 'Detail: ' . $this->task->description . "\n"
                : '',
        );
    }

    /**
     * Moves the plan off TODO before the task runs, since the task's own save is quiet and skips
     * TaskObserver. A plan that turns out to need sign-off is held, and its task stays pending until
     * approval wakes the agent again — including workers already queued in the same band.
     */
    private function planAllowsWork(Plan $plan): bool
    {
        $plan->startWork();

        return PlanStatusEnum::from($plan->status)->isExecutable();
    }

    /**
     * Every status write goes through the transition as a sync change: the batch's completion callback
     * wakes the orchestrator once, so the listener must not wake it per task — but the attempt id,
     * the wake cancellation and the parent waiting on this task all live in the transition and must
     * not be skipped by a bare save.
     */
    private function markInProgress(): void
    {
        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::IN_PROGRESS,
            fromSync: true,
        )->execute();
    }

    private function markDone(string $response): void
    {
        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::DONE,
            result: $this->resultWithSummary($response),
            fromSync: true,
        )->execute();
    }

    /**
     * @param list<string> $childTitles
     */
    private function markWaitingOnChildren(string $response, array $childTitles): void
    {
        $titles = implode(', ', array_map(
            static fn (string $title): string => '"' . Str::limit($title, 80) . '"',
            $childTitles,
        ));

        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::WAITING,
            result: $this->resultWithSummary($response),
            blockedReason: Str::limit('Waiting on delegated work: ' . $titles, 500),
            fromSync: true,
        )->execute();
    }

    /**
     * A model worker's summary is read back into prompts, so it is capped. A factory answer is kept whole:
     * whoever it answers is pointed here for the full text (`RecordChildOutcomeAction`), and a cut copy
     * only sends them back to the factory to ask again. A Learn's answer too: its improvements become the
     * tickets word for word.
     *
     * @return array<string, mixed>
     */
    private function resultWithSummary(string $text): array
    {
        $keptWhole = $this->task->runsInFactory() || FactoryStage::isLearnStep($this->task);

        return [
            ...(is_array($this->task->result) ? $this->task->result : []),
            'worker_summary' => $keptWhole ? $text : Str::limit($text, 4000),
        ];
    }

    /**
     * The reason is the worker's own words. `keepAsResult` also stores them as the summary, so a task
     * blocked by its own report still shows what it found — the reason field is capped at 500 and the
     * detail is often what makes it actionable.
     */
    private function markBlocked(string $reason, bool $keepAsResult = false): void
    {
        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::BLOCKED,
            result: $keepAsResult ? $this->resultWithSummary($reason) : null,
            blockedReason: Str::limit($reason, 500),
            fromSync: true,
        )->execute();
    }
}
