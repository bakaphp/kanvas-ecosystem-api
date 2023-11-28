<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;

class NotificationTypesMessageLogicRepository
{
    /**
     * getNotificationSettings.
     */
    public static function getByMessageType(AppInterface $app, int $messageTypeId): ?NotificationTypesMessageLogic
    {
        return NotificationTypesMessageLogic::where('messages_type_id', $messageTypeId)
            ->fromApp($app)
            ->first();
    }
}
