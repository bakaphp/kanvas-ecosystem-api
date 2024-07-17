<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PeopleObserver
{
    public function created(People $people): void
    {
        $people->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                'app' => $people->app,
            ]
        );
        
        $people->clearLightHouseCache();
    }

    public function updated(People $people): void
    {
        $people->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $people->app,
            ]
        );

        $people->clearLightHouseCache();
    }
}
