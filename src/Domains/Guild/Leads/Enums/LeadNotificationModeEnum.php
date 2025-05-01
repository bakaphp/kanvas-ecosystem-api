<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadNotificationModeEnum: string
{
    case NOTIFY_ALL = 'NOTIFY_ALL';
    case NOTIFY_AGENTS = 'NOTIFY_AGENTS';
    case NOTIFY_LEAD = 'NOTIFY_LEAD';

    public static function get(string $value): LeadNotificationModeEnum
    {
        return match ($value) {
            'NOTIFY_ALL' => self::NOTIFY_ALL,
            'NOTIFY_AGENTS' => self::NOTIFY_AGENTS,
            'NOTIFY_LEAD' => self::NOTIFY_LEAD,
            default => self::NOTIFY_ALL,
        };
    }
}
