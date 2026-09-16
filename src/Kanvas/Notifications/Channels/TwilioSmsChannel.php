<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Twilio\Exceptions\RestException;

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

        try {
            $client->messages->create(
                $cellphone,
                [
                    'from' => $fromPhone,
                    'body' => $content,
                ]
            );
        } catch (RestException $exception) {
            if ($exception->getStatusCode() !== 400) {
                throw $exception;
            }

            Log::channel('single')->warning('Twilio SMS rejected with HTTP 400', [
                'companies_id' => $company->getId(),
                'notification' => $notification::class,
                'twilio_code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
