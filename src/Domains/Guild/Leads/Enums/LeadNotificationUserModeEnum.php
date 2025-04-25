<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadNotificationUserModeEnum: string
{
    case NOTIFY_ROTATION_USERS = 'NOTIFY_ROTATION_USERS';
    case NOTIFY_OWNER = 'NOTIFY_OWNER';

    public static function get(string $value): LeadNotificationUserModeEnum
    {
        return match ($value) {
            'NOTIFY_ROTATION_USERS' => self::NOTIFY_ROTATION_USERS,
            'NOTIFY_OWNER' => self::NOTIFY_OWNER,
            default => self::NOTIFY_OWNER,
        };
    }
}
