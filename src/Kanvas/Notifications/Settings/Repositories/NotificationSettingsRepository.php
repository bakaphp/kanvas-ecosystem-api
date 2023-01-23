<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Settings\Repositories;

use Kanvas\Notifications\Settings\Models\UsersNotificationsSettings;

class NotificationSettingsRepository
{
    /**
     * getNotificationSettings.
     *
     * @param  int $userId
     * @param  int $appId
     *
     * @return UsersNotificationsSettings
     */
    public static function getNotificationSettings(int $userId, int $appId): UsersNotificationsSettings
    {
        return UsersNotificationsSettings::where('users_id', $userId)
            ->where('apps_id', $appId)
            ->get();
    }

    /**
     * getNotificationSettingsByType.
     *
     * @param  int $userId
     * @param  int $appId
     * @param  int $notificationTypeId
     *
     * @return UsersNotificationsSettings
     */
    public static function getNotificationSettingsByType(int $userId, int $appId, int $notificationTypeId): ?UsersNotificationsSettings
    {
        return UsersNotificationsSettings::where('users_id', $userId)
            ->where('apps_id', $appId)
            ->where('notifications_types_id', $notificationTypeId)
            ->first();
    }
}
