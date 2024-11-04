<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Actions;

use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;

class CreatePlanAction
{
    public function __construct(
        protected PlanDto $dto
    ) {
    }

    public function execute(): Plan
    {
        return Plan::firstOrCreate([
            'stripe_id' => $this->dto->stripe_id,
            'apps_id' => $this->dto->app->getId(),
        ], [
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'free_trial_dates' => $this->dto->free_trial_dates,
            'is_default' => $this->dto->is_default,
        ]);
    }
}
