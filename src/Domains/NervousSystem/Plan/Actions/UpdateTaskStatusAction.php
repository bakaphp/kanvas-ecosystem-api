<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Task;

class UpdateTaskStatusAction
{
    public function __construct(
        protected readonly Task $task,
        protected readonly TaskStatusEnum $newStatus,
        protected readonly ?array $result = null,
        protected readonly ?string $blockedReason = null,
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

            if (in_array($newStatus, ['done', 'skipped', 'blocked'], true)
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

            if ($this->blockedReason !== null) {
                $this->task->blocked_reason = $this->blockedReason;
            }

            $this->task->status = $newStatus;
            $this->task->saveOrFail();

            $plan = $this->task->plan;
            if ($plan !== null) {
                $plan->recomputeCompletionPct();
            }

            $eventType = match ($this->newStatus) {
                TaskStatusEnum::IN_PROGRESS => 'plan.task.started',
                TaskStatusEnum::DONE => 'plan.task.completed',
                TaskStatusEnum::BLOCKED => 'plan.task.blocked',
                TaskStatusEnum::SKIPPED => 'plan.task.skipped',
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
                changeType: PlanBroadcast::CHANGE_TASK_STATUS_CHANGED,
                task: $this->task,
                previousStatus: $oldStatus,
            );

            return $this->task->fresh() ?? $this->task;
        });
    }
}
