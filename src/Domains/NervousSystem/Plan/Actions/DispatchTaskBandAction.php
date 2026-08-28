<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Services\PlanBudgetService;
use Throwable;

/**
 * Runs the tasks that can run at once, and wakes the orchestrator when they are done.
 *
 * "At once" means sharing the lowest open `sequence`. `Task` has no dependency column, and its
 * sequence is auto-incremented per task — so by default every task is its own band and this degrades
 * to exactly the serial behaviour that shipped before. Parallelism only happens when an agent
 * deliberately gives tasks the same number, which is why `AddNervousSystemTaskTool`'s description now
 * says what that number means. Prompt text is the whole mechanism; without it this never fires.
 *
 * The batch's completion is the single wake. Letting each worker wake the plan would give the
 * orchestrator N turns to say the same thing, and N chances to act on a half-finished band.
 */
class DispatchTaskBandAction
{
    public function __construct(
        private readonly Plan $plan,
        private readonly ?PlanBudgetService $budget = null,
    ) {
    }

    /**
     * @return array{dispatched: int, deferred: int, sequence: int|null}
     */
    public function execute(): array
    {
        $band = $this->nextBand();

        if ($band === []) {
            return ['dispatched' => 0, 'deferred' => 0, 'sequence' => null];
        }

        $allowed = $this->affordable($band);
        $deferred = count($band) - count($allowed);

        if ($allowed === []) {
            $this->plan->emitLedgerEvent('plan.band.deferred', payload: [
                'sequence' => $band[0]->sequence,
                'deferred' => $deferred,
                'reason' => 'no budget headroom for any worker in this band',
            ]);

            return ['dispatched' => 0, 'deferred' => $deferred, 'sequence' => $band[0]->sequence];
        }

        $plan = $this->plan;

        Bus::batch(array_map(static fn (Task $task): RunTaskWorkerJob => new RunTaskWorkerJob($task), $allowed))
            ->name(sprintf('plan-%d-band-%d', $plan->getId(), $band[0]->sequence))
            ->allowFailures()
            ->then(static function (Batch $batch) use ($plan): void {
                WakeAgentForPlanJob::dispatch(
                    $plan,
                    WakeAgentForPlanJob::REASON_TASK_COMPLETED,
                    sprintf('%d task(s) in this batch finished.', $batch->totalJobs),
                );
            })
            ->onQueue('agent-task-worker')
            ->dispatch();

        $this->plan->emitLedgerEvent('plan.band.dispatched', payload: [
            'sequence' => $band[0]->sequence,
            'dispatched' => count($allowed),
            'deferred' => $deferred,
        ]);

        return [
            'dispatched' => count($allowed),
            'deferred' => $deferred,
            'sequence' => $band[0]->sequence,
        ];
    }

    /**
     * The lowest open sequence and everything sharing it. Taking only the lowest is what preserves
     * ordering: a task at sequence 2 waits until every task at sequence 1 has finished.
     *
     * @return list<Task>
     */
    private function nextBand(): array
    {
        /** @var list<Task> $pending */
        $pending = $this->plan->tasks()
            ->where('is_deleted', 0)
            ->where('status', TaskStatusEnum::PENDING->value)
            ->orderBy('sequence')
            ->get()
            ->all();

        if ($pending === []) {
            return [];
        }

        $lowest = $pending[0]->sequence;

        return array_values(array_filter(
            $pending,
            static fn (Task $task): bool => $task->sequence === $lowest,
        ));
    }

    /**
     * Trim the band to what the plan can still afford.
     *
     * Partial rather than all-or-nothing: progress plus a warning beats a stall, and the next wake
     * picks up the remainder. Hermes caps its batches against the parent's remaining headroom for the
     * same reason — a fan-out that spends the whole allowance in one tick is not parallelism, it is a
     * bill arriving faster.
     *
     * @param list<Task> $band
     * @return list<Task>
     */
    private function affordable(array $band): array
    {
        $budget = $this->budget ?? new PlanBudgetService();

        try {
            $cap = $budget->capUsd($this->plan);
        } catch (Throwable) {
            return $band;
        }

        if ($cap === null) {
            return $band;
        }

        $remaining = $cap - $budget->spend($this->plan)['cost_usd'];

        if ($remaining <= 0) {
            return [];
        }

        // No per-task estimate exists, so the share is the honest approximation: let the band spend at
        // most what is left, split evenly, and never fewer than one worker while any headroom remains.
        $affordableCount = max(1, (int) floor($remaining / max($this->perWorkerEstimate(), 0.01)));

        return array_slice($band, 0, $affordableCount);
    }

    /**
     * What one worker is assumed to cost. A blunt constant rather than a model: it exists to stop a
     * fifty-task band emptying the budget in one tick, not to predict spend.
     */
    private function perWorkerEstimate(): float
    {
        return 0.25;
    }
}
