<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Actions;

use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;

class UpdatePlan
{
    public function __construct(
        protected Plan $plan,
        protected PlanDto $planDto
    ) {
    }

    public function execute(): Plan
    {
        $this->plan->update([
            'name' => $this->planDto->name,
            'description' => $this->planDto->description,
            'free_trial_dates' => $this->planDto->free_trial_dates,
            'is_active' => $this->planDto->is_active,
            'is_default' => $this->planDto->is_default,
        ]);

        return $this->plan;
    }
}
