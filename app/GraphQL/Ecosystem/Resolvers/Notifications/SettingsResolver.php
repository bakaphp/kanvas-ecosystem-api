<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Resolvers\Notifications;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Settings\Actions\MuteAll;
use Kanvas\Notifications\Settings\Actions\SetNotificationSettings;
use Kanvas\Notifications\Settings\Models\UsersNotificationsSettings;

class SettingsResolver
{
    /**
     * mute.
     *
     * @return string
     */
    public function muteAll(): string
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $action = new MuteAll($user, $app);
        $action->execute();
        return 'All Notifications are muted';
    }

    /**
     * setNotificationSettings.
     *
     * @return UsersNotificationsSettings
     */
    public function setNotificationSettings($_, array $request): UsersNotificationsSettings
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $notificationType = NotificationTypes::findOrFail($request['notifications_types_id']);
        $action = new SetNotificationSettings($user, $app, $notificationType);
        return $action->execute();
    }
}
