<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Models\Plan;

class PlanObserver
{
    /**
     * Required: the GraphQL `files` relation on NervousSystemPlan is
     * `@cacheRedis`. Without this clear, uploaded files don't appear
     * until the TTL expires.
     */
    public function updating(Plan $plan): void
    {
        $plan->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
