<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Actions;

use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;

class CreatePlan
{
    public function __construct(
        protected PlanDto $planDto
    ) {
    }

    public function execute(): Plan
    {
        return Plan::create([
            'name' => $this->planDto->name,
            'price' => $this->planDto->price,
            'interval' => $this->planDto->interval,
            'description' => $this->planDto->description,
            'companies_id' => $this->planDto->company->getId(),
            'apps_id' => $this->planDto->app->getId(),
        ]);
    }
}