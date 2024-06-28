<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Client;

class RespondIoSmsChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toRespondIoSms($notifiable);

        if (empty($message)) {
            return;
        }

        $client = new Client(
            Apps::getById($notifiable->apps_id),
            Companies::getById($notifiable->companies_id)
        );

        $cellPhone = $notifiable->phones?->first()?->value;

        if (empty($cellPhone)) {
            return;
        }

        $client->sendMessage($cellPhone, $message);
    }
}
