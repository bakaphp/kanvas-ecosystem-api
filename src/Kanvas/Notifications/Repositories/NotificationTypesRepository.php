<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypes;

class NotificationTypesRepository
{
    /**
     * getNotificationSettings.
     */
    public static function getByName(string $name, AppInterface $app): ?NotificationTypes
    {
        return NotificationTypes::where('name', $name)
            ->where('is_published', 1)
            ->where('is_deleted', 0)
            ->fromApp($app)
            ->first();
    }
}
