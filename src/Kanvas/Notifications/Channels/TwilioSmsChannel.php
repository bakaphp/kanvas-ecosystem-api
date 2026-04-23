<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;

class TwilioSmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toSms($notifiable);
        if (empty($message)) {
            return;
        }

        $company = $message['company'];
        $cellphone = $message['phone'];
        $content = $message['content'];
        $fromPhone = $company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

        if (empty($fromPhone) || empty($content) || empty($cellphone)) {
            return;
        }

        $client = Client::getInstanceByCompany($company);

        $client->messages->create(
            $cellphone, // to
            [
                'from' => $fromPhone,
                'body' => $content,
            ]
        );
    }
}
