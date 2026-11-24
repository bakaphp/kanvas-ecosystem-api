<?php

declare(strict_types=1);

namespace Tests\Stubs\Organizations;

use Tests\Stubs\Workflow\RecordingActivity;

/**
 * Stands in for a connector activity hung on Organization `created` (e.g. PushOrganizationActivity):
 * records what the rule engine handed it so a test can assert whether it fired at all.
 */
class RecordingOrganizationCreatedActivity extends RecordingActivity
{
    /** @var list<array{entity_id: int, params: array<string, mixed>}> */
    public static array $calls = [];
}
