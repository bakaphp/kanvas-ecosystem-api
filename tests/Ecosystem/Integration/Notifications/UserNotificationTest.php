<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class UserNotificationTest extends TestCase
{
    public function testCanBeNotified()
    {
        $user = auth()->user();
        $lead = Lead::factory()->create();

        $this->assertFalse($user->hasBeenNotified($lead));
    }
}
