<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadGroupStatusEnum: string
{
    case WAITING = 'waiting';
    case CONTACTED = 'contacted';

    public static function get(string $value): LeadGroupStatusEnum
    {
        return match (strtolower($value)) {
            'waiting' => self::WAITING,
            'contacted' => self::CONTACTED,
            default => self::WAITING,
        };
    }
}
