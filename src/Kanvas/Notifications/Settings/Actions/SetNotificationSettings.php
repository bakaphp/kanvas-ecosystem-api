<?php
declare(strict_types=1);
namespace Kanvas\Notifications\Settings\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Settings\Models\UsersNotificationsSettings;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Settings\Repositories\NotificationSettings;

class SetNotificationSettings
{
    /**
     * __construct
     *
     * @param  Users $user
     * @param  Apps $app
     * @param  NotificationTypes $notificationType
     * @param  array $data
     * @return void
     */
    public function __construct(
        public Users $user,
        public Apps $app,
        public NotificationTypes $notificationType,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute(): UsersNotificationsSettings
    {
        $notificationSettings = NotificationSettings::getNotificationSettingsByType($this->user->id, $this->app->id, $this->notificationType->id);

        if (!$notificationSettings) {
            $notificationSettings = new UsersNotificationsSettings();
            $notificationSettings->users_id = $this->user->id;
            $notificationSettings->apps_id = $this->app->id;
            $notificationSettings->notifications_types_id = $this->notificationType->id;
            $notificationSettings->is_enabled = (int)false;
            $notificationSettings->channels = json_encode([]);
        } else {
            $notificationSettings->is_enabled = (int) !$notificationSettings->is_enabled;
        }
        $notificationSettings->saveOrFail();
        return $notificationSettings;
    }
}
