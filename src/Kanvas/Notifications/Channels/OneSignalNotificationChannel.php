<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Services\OneSignalService;
use Kanvas\Users\Models\Users;
use Throwable;

class OneSignalNotificationChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $oneSignalMessage = $notification->toOneSignal($notifiable);

        if (empty($oneSignalMessage)) {
            return;
        }

        /** @var Apps $app */
        $app = Apps::getById($oneSignalMessage['apps_id']);

        try {
            $oneSignalService = new OneSignalService($app);
        } catch (Throwable $e) {
            Log::warning('OneSignal push skipped: ' . $e->getMessage(), [
                'apps_id' => $app->getId(),
                'notification' => $notification::class,
            ]);

            return;
        }

        $additionalData = $oneSignalMessage['data'] ?? [];
        unset($additionalData['apps_id'],
            $additionalData['entity'],
            $additionalData['app'],
            $additionalData['options'],
            $additionalData['fromUser'],
            $additionalData['via'],
            $additionalData['email_template'],
            $additionalData['push_template'],
            $additionalData['company'],
            $additionalData['user']);

        //remove any object from the additional data as one signal does not support it
        foreach ($additionalData as $key => $value) {
            if (is_object($value)) {
                unset($additionalData[$key]);
            }
        }

        $oneSignalService->sendNotificationToUser(
            $oneSignalMessage['message'],
            Users::getById($oneSignalMessage['user_id']),
            $url = null,
            $additionalData,
            $buttons = null,
            $schedule = null,
            $oneSignalMessage['subtitle'],
            $oneSignalMessage['title']
        );
    }
}
