<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Kanvas\Connectors\Twilio\Channels\TwilioNotificationChannel;
use Kanvas\Connectors\Twilio\Traits\TwilioNotificationTrait;

class SmsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    use TwilioNotificationTrait;

    public function via($notifiable): array
    {
        return [TwilioNotificationChannel::class];
    }
}
