<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Scheduling\Actions\CancelTaskWakesAction;

/**
 * The one path a task's status moves through. Every writer — the agent's tool, a worker, the coding
 * finalizer, a wake that reopens a wait — comes here, because the things that hang off a transition
 * (the attempt id, the booked wake, the parent waiting on this task) are only right if nobody
 * bypasses them with a bare save.
 */
class UpdateTaskStatusAction
{
    public function __construct(
        protected readonly Task $task,
        protected readonly TaskStatusEnum $newStatus,
        protected readonly ?array $result = null,
        protected readonly ?string $blockedReason = null,
        protected readonly bool $fromSync = false,
        protected readonly ?Carbon $wakeAt = null,
        protected readonly ?int $firingWakeId = null,
        protected readonly ?int $sessionId = null,
    ) {
    }

    public function execute(): Task
    {
        return DB::connection('intelligence')->transaction(function (): Task {
            $oldStatus = $this->task->status;
            $newStatus = $this->newStatus->value;

            if ($newStatus === TaskStatusEnum::IN_PROGRESS->value
                && $this->task->started_at === null
            ) {
                $this->task->started_at = Carbon::now();
            }

            // A fresh attempt each time work starts, so a reopened task's second ending is not the
            // first one's replay. A wait coming back to in_progress is the same attempt continuing.
            $continuesAttempt = $this->task->current_attempt_id !== null
                && in_array($oldStatus, [TaskStatusEnum::IN_PROGRESS->value, TaskStatusEnum::WAITING->value], true);

            if ($newStatus === TaskStatusEnum::IN_PROGRESS->value && ! $continuesAttempt) {
                $this->task->current_attempt_id = Str::uuid()->toString();
            }

            if (in_array($this->newStatus, TaskStatusEnum::completedStatuses(), true)
                && $this->task->completed_at === null
            ) {
                $this->task->completed_at = Carbon::now();
            }

            if ($newStatus === TaskStatusEnum::PENDING->value) {
                $this->task->started_at = null;
                $this->task->completed_at = null;
            }

            if ($this->result !== null) {
                $this->task->result = $this->result;
            }

            // The reason describes the status the task is in. A task that completes after a block or
            // a wait would otherwise report "done — Reason: waiting on Charlie" to whoever it wakes.
            if ($this->blockedReason !== null) {
                $this->task->blocked_reason = $this->blockedReason;
            } elseif (in_array($this->newStatus, TaskStatusEnum::completedStatuses(), true)) {
                $this->task->blocked_reason = null;
            }

            // The wake belongs to the wait: set with it, cleared on the way out of it.
            $this->task->wake_at = $this->newStatus === TaskStatusEnum::WAITING ? $this->wakeAt : null;

            $this->task->status = $newStatus;
            $this->task->saveOrFail();

            // A task that closes, or leaves its wait, has no use for a booked wake. A re-snooze is
            // WAITING → WAITING and books its own replacement, so it is not a departure.
            $leavesWait = $oldStatus === TaskStatusEnum::WAITING->value
                && $this->newStatus !== TaskStatusEnum::WAITING;

            if ($this->newStatus->isTerminal() || $leavesWait) {
                new CancelTaskWakesAction($this->task, $this->firingWakeId)->execute();
            }

            if ($this->newStatus->isTerminal()) {
                // Inside this transaction on purpose: the parent is locked while it leaves its wait,
                // and the wake that hands it the result is queued only once both rows have committed.
                if ($this->task->parent_task_id !== null) {
                    new RecordChildOutcomeAction($this->task, $this->sessionId)->execute();
                }

                new CloseFollowUpPlanAction($this->task)->execute();
            }

            $plan = $this->task->plan;
            if ($plan !== null) {
                $plan->recomputeCompletionPct();

                // Roll the change up to the owning project (if the plan belongs to one) so the
                // project's completion stays live regardless of who moved the task.
                if ($plan->project_id !== null) {
                    Project::query()->where('id', $plan->project_id)->first()?->recomputeCompletionPct();
                }
            }

            $eventType = match ($this->newStatus) {
                TaskStatusEnum::IN_PROGRESS => 'plan.task.started',
                TaskStatusEnum::DONE => 'plan.task.completed',
                TaskStatusEnum::BLOCKED => 'plan.task.blocked',
                TaskStatusEnum::SKIPPED => 'plan.task.skipped',
                TaskStatusEnum::WAITING => 'plan.task.waiting',
                TaskStatusEnum::PENDING => 'plan.task.reset',
            };

            $eventStatus = $this->newStatus === TaskStatusEnum::BLOCKED
                ? EventStatusEnum::ERROR
                : EventStatusEnum::INFO;

            $this->task->emitLedgerEvent(
                eventType: $eventType,
                status: $eventStatus,
                payload: [
                    'plan_id' => $this->task->plan_id,
                    'sequence' => $this->task->sequence,
                    'status_from' => $oldStatus,
                    'status_to' => $newStatus,
                    'completion_pct' => $plan?->completion_pct,
                ],
                result: $this->result,
                error: $this->blockedReason !== null
                    ? ['reason' => $this->blockedReason]
                    : null,
            );

            $plan?->broadcastChange(
                changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
                task: $this->task,
                previousStatus: $oldStatus,
                fromSync: $this->fromSync,
            );

            return $this->task->fresh() ?? $this->task;
        });
    }
}
