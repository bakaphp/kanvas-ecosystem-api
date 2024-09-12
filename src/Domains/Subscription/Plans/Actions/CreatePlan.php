<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Actions;

use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;

class CreatePlan
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
            'companies_id' => $this->dto->company->getId(),
        ], [
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'is_default' => $this->dto->is_default,
            'is_deleted' => $this->dto->is_deleted,
            'users_id' => $this->dto->user->getId(),
        ]);
    }
}