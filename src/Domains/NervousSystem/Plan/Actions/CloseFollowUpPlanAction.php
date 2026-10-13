<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * A follow-up plan is its one task: when the task ends, the plan ends the same way. Saved through
 * the observer on purpose — the completed- and blocked-plan alerts are what tell the person who asked,
 * in their own conversation and by notification, without a turn of the agent's.
 */
class CloseFollowUpPlanAction
{
    public function __construct(
        private readonly Task $task,
    ) {
    }

    public function execute(): ?Plan
    {
        /** @var Plan|null $plan */
        $plan = $this->task->plan;

        if ($plan === null || $plan->plan_type !== OpenFollowUpAction::PLAN_TYPE || ! $this->task->isTerminal()) {
            return null;
        }

        $status = match ($this->task->status) {
            TaskStatusEnum::DONE->value => PlanStatusEnum::DONE,
            TaskStatusEnum::SKIPPED->value => PlanStatusEnum::CANCELLED,
            default => PlanStatusEnum::BLOCKED,
        };

        $plan->status = $status->value;

        if ($status === PlanStatusEnum::DONE) {
            $plan->completed_at = Carbon::now();
        } else {
            $plan->error_message = $this->task->blocked_reason;
        }

        $plan->save();

        return $plan;
    }
}
