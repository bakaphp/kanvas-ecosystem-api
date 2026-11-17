<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Tests\Intelligence\Agents\Tools\Support\RecordingWorkflowActivity;
use Tests\Stubs\Workflow\RecordingActivity;

/**
 * Commit stub activities before opening test transactions: the global catalog's unique
 * `model_name` otherwise makes parallel tests wait on each other's uncommitted inserts.
 * Add new stub activities to STUB_ACTIVITIES.
 */
trait CataloguesStubActivities
{
    private const array STUB_ACTIVITIES = [
        PlainRecordingActivity::class => 'Test Plain Recording',
        RecordingPullActivity::class => 'Test Recording Pull',
        RecordingActivity::class => 'Test Recording',
        RecordingWorkflowActivity::class => 'Test Recording Workflow',
    ];

    private static bool $stubActivitiesCatalogued = false;

    /**
     * Laravel opens the test's database transactions from here, so this is the last point where a
     * write still commits on its own.
     */
    protected function setUpTraits()
    {
        if (! self::$stubActivitiesCatalogued) {
            foreach (self::STUB_ACTIVITIES as $activity => $name) {
                Action::query()->firstOrCreate(
                    ['model_name' => $activity],
                    ['name' => $name, 'kind' => ActionKindEnum::WORKFLOW->value]
                );
            }

            self::$stubActivitiesCatalogued = true;
        }

        return parent::setUpTraits();
    }
}
