<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\UsersNotificationsSettings;

class NotificationSettingsRepository
{
    /**
     * getNotificationSettings.
     */
    public static function getNotificationSettings(UserInterface $user, AppInterface $app): ?UsersNotificationsSettings
    {
        return UsersNotificationsSettings::where('users_id', $user->getId())
            ->where('apps_id', $app->getId())
            ->first();
    }

    /**
     * getNotificationSettingsByType.
     *
     * @return UsersNotificationsSettings
     */
    public static function getNotificationSettingsByType(
        UserInterface $user,
        AppInterface $app,
        NotificationTypes $notificationType
    ): ?UsersNotificationsSettings {
        return UsersNotificationsSettings::where('users_id', $user->getId())
            ->where('apps_id', $app->getId())
            ->where('notifications_types_id', $notificationType->getId())
            ->first();
    }
}
