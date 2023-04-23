<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Settings\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Settings\Models\UsersNotificationsSettings;
use Kanvas\Users\Models\Users;

class MuteAllNotificationAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Users $user,
        public Apps $app
    ) {
    }

    /**
     * execute.
     */
    public function execute(): void
    {
        $notificationsTypes = NotificationTypes::where('apps_id', $this->app->id)
                            ->where('is_deleted', 0)
                            ->where('is_published', 1)
                            ->get();
        foreach ($notificationsTypes as $type) {
            UsersNotificationsSettings::updateOrCreate([
                'users_id' => $this->user->id,
                'apps_id' => $this->app->id,
                'notifications_types_id' => $type->id,
            ], [
                'is_enabled' => 0,
            ]);
        }
    }
}
