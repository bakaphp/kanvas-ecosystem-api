<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Settings\Actions\MuteAllNotificationAction;
use Kanvas\Notifications\Settings\Actions\SetNotificationSettingAction;
use Kanvas\Notifications\Settings\Models\UsersNotificationsSettings;

class NotificationSettingsMutation
{
    /**
     * mute.
     */
    public function muteAll(): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $action = new MuteAllNotificationAction($user, $app);
        $action->execute();

        return true;
    }

    /**
     * setNotificationSettings.
     */
    public function setNotificationSettings($_, array $request): UsersNotificationsSettings
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $notificationType = NotificationTypes::findOrFail($request['notifications_types_id']);
        $action = new SetNotificationSettingAction($user, $app, $notificationType);

        return $action->execute($request['channels']);
    }
}
