<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Actions;

use Kanvas\Subscription\Plans\Models\Plan;

class DeletePlan
{
    public function __construct(
        protected Plan $plan
    ) {
    }

    public function execute(): bool
    {
        return $this->plan->delete();
    }
}