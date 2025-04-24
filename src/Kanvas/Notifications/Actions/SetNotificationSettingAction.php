<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\UsersNotificationsSettings;
use Kanvas\Notifications\Repositories\NotificationSettingsRepository;
use Kanvas\Users\Models\Users;

class SetNotificationSettingAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Users $user,
        public Apps $app,
        public NotificationTypes $notificationType,
    ) {
    }

    /**
     * execute.
     */
    public function execute(array $channels = []): UsersNotificationsSettings
    {
        $notificationSettings = NotificationSettingsRepository::getNotificationSettingsByType($this->user, $this->app, $this->notificationType);

        if (! $notificationSettings) {
            $notificationSettings = new UsersNotificationsSettings();
            $notificationSettings->users_id = $this->user->id;
            $notificationSettings->apps_id = $this->app->id;
            $notificationSettings->notifications_types_id = $this->notificationType->id;
            $notificationSettings->is_enabled = (int) false;
            $notificationSettings->channels = $channels;
        } else {
            $notificationSettings->is_enabled = (int) ! $notificationSettings->is_enabled;
        }
        $notificationSettings->saveOrFail();

        return $notificationSettings;
    }
}
