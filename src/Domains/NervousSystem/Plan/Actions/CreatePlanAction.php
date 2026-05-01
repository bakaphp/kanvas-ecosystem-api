<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

class CreatePlanAction
{
    /**
     * @param  array<int, TaskData>  $tasks  optional initial tasks to create with the plan
     */
    public function __construct(
        protected readonly PlanData $data,
        protected readonly array $tasks = [],
    ) {
    }

    public function execute(): Plan
    {
        if ($this->data->agent === null && $this->data->user === null) {
            throw new ValidationException('A plan must have at least one of agent or user set.');
        }

        return DB::connection('intelligence')->transaction(function (): Plan {
            $effectiveStatus = $this->data->requiresHumanApproval
                && $this->data->status === PlanStatusEnum::ACTIVE
                ? PlanStatusEnum::AWAITING_APPROVAL
                : $this->data->status;

            $plan = new Plan();
            $plan->apps_id = $this->data->app->getId();
            $plan->companies_id = $this->data->company->getId();
            $plan->agent_id = $this->data->agent?->getId();
            $plan->users_id = $this->data->user?->getId();
            $plan->parent_plan_id = $this->data->parentPlan?->id;
            $plan->entity_namespace = $this->data->entityNamespace;
            $plan->entity_id = $this->data->entityId;
            $plan->plan_type = $this->data->planType;
            $plan->title = $this->data->title;
            $plan->description = $this->data->description;
            $plan->status = $effectiveStatus->value;
            $plan->priority = $this->data->priority;
            $plan->completion_pct = 0;
            $plan->deadline_at = $this->data->deadlineAt;
            $plan->input = $this->data->input;
            $plan->output = $this->data->output;
            $plan->confidence_score = $this->data->confidenceScore !== null
                ? (string) $this->data->confidenceScore
                : null;
            $plan->requires_human_approval = $this->data->requiresHumanApproval;
            $plan->saveOrFail();

            foreach ($this->tasks as $taskData) {
                $task = new Task();
                $task->plan_id = $plan->id;
                $task->apps_id = $plan->apps_id;
                $task->companies_id = $plan->companies_id;
                $task->sequence = $taskData->sequence;
                $task->title = $taskData->title;
                $task->description = $taskData->description;
                $task->status = $taskData->status->value;
                $task->result = $taskData->result;
                $task->blocked_reason = $taskData->blockedReason;
                $task->saveOrFail();
            }

            if (! empty($this->tasks)) {
                $plan->refresh();
                $plan->recomputeCompletionPct();
            }

            $plan->emitLedgerEvent('plan.created', payload: [
                'plan_type' => $plan->plan_type,
                'title' => $plan->title,
                'status' => $plan->status,
                'task_count' => count($this->tasks),
            ]);

            return $plan;
        });
    }
}
