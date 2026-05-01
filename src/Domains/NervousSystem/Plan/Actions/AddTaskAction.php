<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

class AddTaskAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly TaskData $data,
    ) {
    }

    public function execute(): Task
    {
        return DB::connection('intelligence')->transaction(function (): Task {
            $task = new Task();
            $task->plan_id = $this->plan->id;
            $task->apps_id = $this->plan->apps_id;
            $task->companies_id = $this->plan->companies_id;
            $task->sequence = $this->data->sequence !== 0
                ? $this->data->sequence
                : (int) $this->plan->tasks()->max('sequence') + 1;
            $task->title = $this->data->title;
            $task->description = $this->data->description;
            $task->status = $this->data->status->value;
            $task->result = $this->data->result;
            $task->blocked_reason = $this->data->blockedReason;
            $task->saveOrFail();

            $this->plan->refresh();
            $this->plan->recomputeCompletionPct();

            $task->emitLedgerEvent('plan.task.created', payload: [
                'plan_id' => $this->plan->id,
                'sequence' => $task->sequence,
                'title' => $task->title,
                'status' => $task->status,
            ]);

            return $task;
        });
    }
}
