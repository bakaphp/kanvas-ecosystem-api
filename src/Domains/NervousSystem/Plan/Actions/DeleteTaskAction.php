<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Task;

class DeleteTaskAction
{
    public function __construct(
        protected readonly Task $task,
    ) {
    }

    public function execute(): bool
    {
        if ((bool) $this->task->is_deleted) {
            return true;
        }

        $plan = $this->task->plan;

        DB::connection('intelligence')->transaction(function () use ($plan): void {
            $this->task->softDelete();
            $plan?->recomputeCompletionPct();
        });

        $this->task->emitLedgerEvent('plan.task.deleted', payload: [
            'plan_id' => $this->task->plan_id,
            'sequence' => $this->task->sequence,
            'title' => $this->task->title,
            'completion_pct' => $plan?->completion_pct,
        ]);

        $plan?->broadcastChange(
            changeType: PlanBroadcast::CHANGE_TASK_STATUS_CHANGED,
            task: $this->task,
        );

        return true;
    }
}
