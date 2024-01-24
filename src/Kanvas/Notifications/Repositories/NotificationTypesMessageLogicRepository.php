<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;

class NotificationTypesMessageLogicRepository
{
    /**
     * getNotificationSettings.
     */
    public static function getByNotificationType(AppInterface $app, NotificationTypes $notificationType): ?NotificationTypesMessageLogic
    {
        return NotificationTypesMessageLogic::where('notifications_type_id', $notificationType->getId())
            ->fromApp($app)
            ->first();
    }
}
