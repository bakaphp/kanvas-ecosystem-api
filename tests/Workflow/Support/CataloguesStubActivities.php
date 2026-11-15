<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Tests\Intelligence\Agents\Tools\Support\RecordingWorkflowActivity;
use Tests\Stubs\Workflow\RecordingActivity;

/**
 * The test activities' rows in the `actions` catalog, committed before the test's transactions
 * open. The catalog is global and `model_name` is unique, so a stub inserted inside two tests'
 * open transactions makes one wait on the other's uncommitted row and deadlocks them under
 * paratest (the CI Workflow job, 2026-11-14). Committed once, every test finds the row and none
 * inserts it. A new stub activity that gets a rule goes in STUB_ACTIVITIES.
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
