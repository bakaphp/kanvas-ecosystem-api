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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
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

        $this->markInProgress();

        $startedAt = microtime(true);

        try {
            $response = WorkerToolPolicy::within(fn (): string => new AgentChatKernel(
                agent: $agent,
                session: $this->workerSession(),
                message: $this->brief(),
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

    private function markInProgress(): void
    {
        $this->task->status = TaskStatusEnum::IN_PROGRESS->value;
        $this->task->started_at = Carbon::now();
        $this->task->saveQuietly();
    }

    private function markDone(string $response): void
    {
        $this->task->status = TaskStatusEnum::DONE->value;
        $this->task->completed_at = Carbon::now();
        $this->task->result = [
            ...(is_array($this->task->result) ? $this->task->result : []),
            'worker_summary' => Str::limit($response, 4000),
        ];
        // Quiet: the batch's completion callback wakes the orchestrator once, rather than every task
        // waking it separately and giving it N turns to say the same thing.
        $this->task->saveQuietly();
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
        $this->task->status = TaskStatusEnum::BLOCKED->value;
        $this->task->blocked_reason = Str::limit($reason, 500);

        if ($keepAsResult) {
            $this->task->result = [
                ...(is_array($this->task->result) ? $this->task->result : []),
                'worker_summary' => Str::limit($reason, 4000),
            ];
        }

        $this->task->saveQuietly();
    }
}
