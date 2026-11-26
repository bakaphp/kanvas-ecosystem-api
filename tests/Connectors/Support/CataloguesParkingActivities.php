<?php

declare(strict_types=1);

namespace Tests\Connectors\Support;

use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingExitDecisionActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;

trait CataloguesParkingActivities
{
    private static bool $parkingActivitiesCatalogued = false;

    protected function setUpTraits()
    {
        if (! self::$parkingActivitiesCatalogued) {
            $activities = [
                ParkingExitDecisionActivity::class => 'Parking Exit Decision',
                ParkingPassScannedActivity::class => 'Parking Pass Scanned',
            ];

            foreach ($activities as $activity => $name) {
                Action::query()->firstOrCreate(
                    ['model_name' => $activity],
                    ['name' => $name, 'kind' => ActionKindEnum::WORKFLOW->value]
                );
            }

            self::$parkingActivitiesCatalogued = true;
        }

        return parent::setUpTraits();
    }
}
