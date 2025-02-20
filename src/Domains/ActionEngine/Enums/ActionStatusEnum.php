<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Enums;

enum ActionStatusEnum: string
{
    case DOWNLOADED = 'downloaded';
    case SUBMITTED = 'submitted';
    case SENT = 'sent';
    case OPEN = 'opened';
    case ORDER_ASC = 'ASC';
    case ORDER_DESC = 'DESC';

    public static function validate(string $status): bool
    {
        return in_array($status, [
            self::DOWNLOADED->value,
            self::SUBMITTED->value,
            self::SENT->value,
            self::OPEN->value,
        ]);
    }
}
