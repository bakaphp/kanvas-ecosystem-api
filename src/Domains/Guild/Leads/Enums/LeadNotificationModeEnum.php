<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadNotificationModeEnum: string
{
    case NOTIFY_ALL = 'NOTIFY_ALL';
    case NOTIFY_ROTATION_USERS = 'NOTIFY_ROTATION_USERS';
    case NOTIFY_LEAD = 'NOTIFY_LEAD';
    case NOTIFY_OWNER = 'NOTIFY_OWNER';

    public static function get(string $value): LeadNotificationModeEnum
    {
        return match ($value) {
            'NOTIFY_ALL'            => self::NOTIFY_ALL,
            'NOTIFY_ROTATION_USERS' => self::NOTIFY_ROTATION_USERS,
            'NOTIFY_OWNER'          => self::NOTIFY_OWNER,
            'NOTIFY_LEAD'           => self::NOTIFY_LEAD,
            default                 => self::NOTIFY_ALL,
        };
    }
}
