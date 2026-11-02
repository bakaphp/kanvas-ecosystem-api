<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;
use Throwable;

/**
 * Hands a finished child's outcome to whoever was working the parent task.
 *
 * Two ways a task gets worked, so two ways to resume it: a worker that ran it has its own session
 * keyed on the task and is resumed there, with the outcome as its next instruction; a task the plan's
 * own agent was driving is resumed through the plan wake, so the fact lands in the plan's thread.
 *
 * Delivery is claimed on the outcome row before anything runs — the after-commit dispatch and the
 * reconciler can both queue this for the same row, and the parent must hear the result once.
 */
class ResumeParentTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON = 'child_outcome';

    public function __construct(
        public readonly TaskChildOutcome $outcome,
    ) {
        $this->onQueue('agent-task-worker');
    }

    public function handle(): void
    {
        /** @var Task|null $parent */
        $parent = $this->outcome->parent;

        if ($parent === null || $parent->plan === null) {
            return;
        }

        $this->overwriteAppService($parent->plan->app);

        if (! $this->outcome->claimDelivery()) {
            return;
        }

        try {
            $this->resume($parent);
        } catch (Throwable $e) {
            // Released so the reconciler tries again; a claimed-but-undelivered outcome would be the
            // one result the parent never hears.
            $this->outcome->releaseDelivery();

            throw $e;
        }
    }

    private function resume(Task $parent): void
    {
        $fact = $this->fact($parent);

        if ($parent->isTerminal()) {
            $parent->emitLedgerEvent('plan.task.child_outcome_after_close', payload: [
                'child_task_id' => $this->outcome->child_task_id,
                'outcome_id' => $this->outcome->getId(),
                'fact' => $fact,
            ]);

            return;
        }

        $workerHadIt = $this->hasWorkerSession($parent);

        $parent->emitLedgerEvent('plan.task.child_outcome_delivered', payload: [
            'child_task_id' => $this->outcome->child_task_id,
            'outcome_id' => $this->outcome->getId(),
            'child_status' => $this->outcome->status,
            'resumed_via' => $workerHadIt ? 'worker' : 'plan',
        ]);

        if ($workerHadIt) {
            RunTaskWorkerJob::dispatch($parent, $fact);

            return;
        }

        WakeAgentForPlanJob::dispatch($parent->plan, WakeAgentForPlanJob::REASON_CHILD_OUTCOME, $fact);
    }

    private function hasWorkerSession(Task $parent): bool
    {
        return Session::query()
            ->where('apps_id', $parent->apps_id)
            ->where('entity_namespace', Task::class)
            ->where('entity_id', $parent->getId())
            ->exists();
    }

    /**
     * What the parent needs to decide its next move: which child, how it ended, what it produced,
     * and whether the objective it was started under is still the current one.
     */
    private function fact(Task $parent): string
    {
        /** @var Task|null $child */
        $child = $this->outcome->child;
        $title = $child?->title ?? 'a delegated task';
        $result = is_array($child?->result) ? $child->result : [];

        $lines = [sprintf(
            'Task #%d ("%s"), which you delegated from task #%d, is now %s.',
            $this->outcome->child_task_id,
            $title,
            $parent->getId(),
            $this->outcome->status,
        )];

        if ($this->outcome->summary !== null) {
            $lines[] = ($this->outcome->status === TaskStatusEnum::DONE->value ? 'Result: ' : 'Reason: ')
                . $this->outcome->summary;
        }

        foreach (['branch' => 'Branch', 'pull_request' => 'Pull request', 'outcome' => 'Push outcome'] as $key => $label) {
            if (is_string($result[$key] ?? null) && $result[$key] !== '') {
                $lines[] = $label . ': ' . $result[$key];
            }
        }

        if ($this->outcome->revision !== null && $this->outcome->revision < (int) $parent->revision) {
            $lines[] = sprintf(
                'It was started under revision %d of your task; the task has since changed to revision %d, '
                . 'so re-check it against the current objective before relying on it.',
                $this->outcome->revision,
                (int) $parent->revision,
            );
        }

        return implode("\n", $lines);
    }
}
