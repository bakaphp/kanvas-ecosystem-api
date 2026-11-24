<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications\Channels;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Movipass\Notifications\CustomOrderNotification;
use Throwable;

/**
 * Mirrors OneSignalNotificationChannel, which always builds its service from the app-level keys,
 * so it cannot reach the technicians OneSignal app. Kept here instead of adding a hook to the
 * shared channel; if that channel gains a service factory, fold this back into it.
 */
class MovipassOneSignalChannel
{
    public function send(object $notifiable, CustomOrderNotification $notification): void
    {
        $oneSignalMessage = $notification->toOneSignal($notifiable);

        if (empty($oneSignalMessage)) {
            return;
        }

        try {
            $oneSignalService = $notification->oneSignalServiceFor($notifiable);
        } catch (Throwable $e) {
            Log::warning('OneSignal push skipped: ' . $e->getMessage(), [
                'apps_id' => $oneSignalMessage['apps_id'],
                'notification' => $notification::class,
            ]);

            return;
        }

        $additionalData = $oneSignalMessage['data'] ?? [];
        unset(
            $additionalData['apps_id'],
            $additionalData['entity'],
            $additionalData['app'],
            $additionalData['options'],
            $additionalData['fromUser'],
            $additionalData['via'],
            $additionalData['email_template'],
            $additionalData['push_template'],
            $additionalData['company'],
            $additionalData['user']
        );

        $additionalData = array_filter($additionalData, fn ($value): bool => ! is_object($value));

        $oneSignalService->sendNotificationToUser(
            message: $oneSignalMessage['message'],
            user: $notifiable,
            data: $additionalData,
            headings: $oneSignalMessage['title'],
            subtitle: $oneSignalMessage['subtitle'] ?: null,
        );
    }
}
