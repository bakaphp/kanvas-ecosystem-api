<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Enums;

use InvalidArgumentException;

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

    public function getByName(string $name): ActionStatusEnum
    {
        return match ($name) {
            'downloaded' => self::DOWNLOADED,
            'submitted' => self::SUBMITTED,
            'sent' => self::SENT,
            'opened' => self::OPEN,
            default => throw new InvalidArgumentException("Invalid status name: $name"),
        };
    }
}
