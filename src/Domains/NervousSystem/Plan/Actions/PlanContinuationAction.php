<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Plan\DataTransferObject\ContinuationDecision;
use Kanvas\NervousSystem\Plan\Enums\ContinuationDecisionEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Services\PlanBudgetService;

/**
 * Decides what happens next on a plan, from the plan's own rows.
 *
 * A pure function on purpose: no LLM call, no side effects, no writes. That is what makes the loop's
 * control flow testable — every other part of an agent system needs a model in the loop to exercise,
 * this one does not, so its five branches get real CI coverage.
 *
 * It deliberately does NOT create work. EXTEND says "the goal is not met and there is nothing left
 * to do about it" and hands that back to the agent; a deterministic action inventing tasks would be a
 * far larger claim than one noticing there are none.
 */
class PlanContinuationAction
{
    /**
     * Ceiling on re-entries when a plan sets no `max_wakes` of its own. High enough that a genuinely
     * long plan is not cut off mid-flight, low enough that a stuck one stops the same day.
     */
    public const int DEFAULT_MAX_WAKES = 25;

    private readonly PlanBudgetService $budget;

    public function __construct(
        private readonly Plan $plan,
        ?PlanBudgetService $budget = null,
    ) {
        $this->budget = $budget ?? new PlanBudgetService();
    }

    private int $open = 0;
    private int $blocked = 0;
    private int $done = 0;
    private int $total = 0;
    private ?string $firstBlockedReason = null;

    public function execute(): ContinuationDecision
    {
        $this->tally();

        // Budget first: an exhausted plan stops regardless of how much work is left, and checking it
        // last would let a plan with open tasks keep dispatching past its own ceiling.
        if ($this->wakeBudgetExhausted()) {
            return $this->decide(
                ContinuationDecisionEnum::ABANDON,
                sprintf(
                    'Wake budget exhausted after %d re-entries (cap %d).',
                    (int) $this->plan->wake_count,
                    $this->maxWakes(),
                ),
            );
        }

        // Spend is the other ceiling. Checked alongside the wake budget rather than after the status
        // gates, for the same reason: a plan over its ceiling stops regardless of what it could do next.
        $overspend = $this->budget->exceededReason($this->plan);

        if ($overspend !== null) {
            return $this->decide(ContinuationDecisionEnum::ABANDON, $overspend);
        }

        // Intake has no agreed goal yet and approval has not been given — dispatching against either
        // would be acting on something nobody signed off, whatever the tasks say.
        $status = PlanStatusEnum::tryFrom($this->plan->status);

        if ($status !== null && ! $status->isExecutable()) {
            return $this->decide(
                ContinuationDecisionEnum::BLOCK,
                $status === PlanStatusEnum::INTAKE
                    ? 'Plan is still in intake — the brief is not complete, so no work can start.'
                    : 'Plan is awaiting human approval.',
            );
        }

        if ($this->open > 0) {
            return $this->decide(
                ContinuationDecisionEnum::DISPATCH,
                sprintf('%d task(s) still open.', $this->open),
            );
        }

        // Blocked only matters once nothing else can move — a plan with open work should keep going
        // around a blocked task rather than stopping the whole plan on it.
        if ($this->blocked > 0) {
            return $this->decide(
                ContinuationDecisionEnum::BLOCK,
                sprintf(
                    '%d task(s) blocked and nothing else to work: %s',
                    $this->blocked,
                    $this->firstBlockedReason ?? 'no reason recorded',
                ),
            );
        }

        // No tasks at all is EXTEND, not VERIFY: an empty plan has not achieved anything, and calling
        // it verified would close plans that were never worked.
        if ($this->total === 0) {
            return $this->decide(ContinuationDecisionEnum::EXTEND, 'Plan has no tasks yet.');
        }

        return $this->decide(
            ContinuationDecisionEnum::VERIFY,
            sprintf('All %d task(s) complete.', $this->done),
        );
    }

    private function tally(): void
    {
        /** @var iterable<int, Task> $tasks */
        $tasks = $this->plan->tasks()->where('is_deleted', 0)->get();

        foreach ($tasks as $task) {
            $this->total++;

            match ($task->status) {
                TaskStatusEnum::PENDING->value, TaskStatusEnum::IN_PROGRESS->value => $this->open++,
                TaskStatusEnum::DONE->value, TaskStatusEnum::SKIPPED->value => $this->done++,
                TaskStatusEnum::BLOCKED->value => $this->recordBlocked($task),
                default => null,
            };
        }
    }

    private function recordBlocked(Task $task): void
    {
        $this->blocked++;
        $this->firstBlockedReason ??= $task->blocked_reason;
    }

    private function decide(ContinuationDecisionEnum $verdict, string $reason): ContinuationDecision
    {
        return new ContinuationDecision(
            verdict: $verdict,
            reason: $reason,
            openTasks: $this->open,
            blockedTasks: $this->blocked,
            doneTasks: $this->done,
            wakeCount: (int) $this->plan->wake_count,
        );
    }

    private function maxWakes(): int
    {
        $planCap = $this->plan->max_wakes;

        return $planCap !== null && (int) $planCap > 0
            ? (int) $planCap
            : self::DEFAULT_MAX_WAKES;
    }

    private function wakeBudgetExhausted(): bool
    {
        return (int) $this->plan->wake_count >= $this->maxWakes();
    }
}
