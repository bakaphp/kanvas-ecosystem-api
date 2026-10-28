<?php

declare(strict_types=1);

namespace Tests\Stubs\FollowUp;

use Tests\Stubs\Workflow\RecordingActivity;

/**
 * Stands in for a CRM connector activity hung on `after-follow-up`: records what the rule
 * engine handed it so a test can assert the payload a real connector would receive.
 */
class RecordingAfterFollowUpActivity extends RecordingActivity
{
    /** @var list<array{entity_id: int, params: array<string, mixed>}> */
    public static array $calls = [];
}
