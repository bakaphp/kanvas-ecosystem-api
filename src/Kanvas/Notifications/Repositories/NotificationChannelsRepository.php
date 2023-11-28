<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Kanvas\Notifications\Models\NotificationChannel;

class NotificationChannelsRepository
{
    /**
     * getNotificationSettings.
     */
    public static function getBySlug(string $slug): ?NotificationChannel
    {
        return NotificationChannel::where('slug', $slug)
            ->where('is_deleted', 0)
            ->first();
    }
}
