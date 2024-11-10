<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Models\NotificationTypes;
use Tests\TestCase;

final class UserNotificationTest extends TestCase
{
    public function testCanBeNotified()
    {
        $user = auth()->user();
        $lead = Lead::factory()->create();
        $app = $lead->app;
        $notificationType = NotificationTypes::fromApp($app)->first();
        $this->assertFalse($user->hasBeenNotified($lead, $notificationType));
    }
}
