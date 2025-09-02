<?php

namespace Kanvas\Connectors\Twilio\Channels;

use Illuminate\Notifications\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Notifications\Templates\SmsNotification;
class TwilioNotificationChannel
{
    public function send($notifiable, SmsNotification $notification): void
    {
        $company = $notification->getCompany();
        if (! $company) {
            $app = app(Apps::class);
        }
        $client = Client::getInstance($company ?? $app);
        $client->messages->create(
            $notification->getToNumber(),
            [
                'from' => $notification->getFromNumber(),
                'body' => $notification->getMessage(),
            ]
        );
    }
}
