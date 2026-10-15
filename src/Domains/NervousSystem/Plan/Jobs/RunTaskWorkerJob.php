<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
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

    /**
     * Openings that mean the worker is reporting failure rather than a result. Deliberately few and
     * headline-shaped: this decides whether finished work is thrown away, and the opposite error —
     * marking successful work blocked — costs more than the one it prevents.
     *
     * @var list<string>
     */
    private const array SELF_BLOCKED_OPENINGS = [
        'blocked',
        'task status report: blocked',
        'task status: blocked',
        'status: blocked',
        'unable to complete',
        'i was unable to',
        'i cannot complete',
        'i could not complete',
    ];

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
            $response = WorkerToolPolicy::within(fn (): string => new AgentChatKernel(
                agent: $agent,
                session: $this->workerSession(),
                message: $this->resumeFact !== null ? $this->resumeBrief() : $this->brief(),
                user: $owner,
                persistConversation: false,
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
        if ($this->reportsItselfBlocked($response)) {
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

        $this->wakeOrchestratorIfUnbatched();
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
        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->task->apps_id,
                'companies_id' => $this->task->companies_id,
                'entity_namespace' => Task::class,
                'entity_id' => $this->task->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->task->agent_id ?? $this->task->plan->agent_id,
                'channel_id' => null,
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
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
     * @return array<string, mixed>
     */
    private function resultWithSummary(string $text): array
    {
        return [
            ...(is_array($this->task->result) ? $this->task->result : []),
            'worker_summary' => Str::limit($text, 4000),
        ];
    }

    /**
     * Does the worker's own answer say it could not do the work?
     *
     * Anchored to the OPENING of the response, and to headline forms only. A task that mentions being
     * blocked halfway through ("I unblocked the pipeline, then counted") has not failed, and matching
     * the word anywhere would mark successful work as blocked — a worse error than the one this fixes,
     * because it stops work that actually completed.
     */
    private function reportsItselfBlocked(string $response): bool
    {
        $opening = mb_strtolower(trim(Str::limit(strip_tags($response), 200, '')));
        $opening = trim(preg_replace('/^[#*\s>_`-]+/u', '', $opening) ?? $opening);

        foreach (self::SELF_BLOCKED_OPENINGS as $marker) {
            if (str_starts_with($opening, $marker)) {
                return true;
            }
        }

        return false;
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
