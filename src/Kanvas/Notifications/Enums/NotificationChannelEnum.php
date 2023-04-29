<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

use Kanvas\Exceptions\ValidationException;

enum NotificationChannelEnum: int
{
    case MAIL = 1;
    case PUSH = 2;
    case REALTIME = 3;
    case SMS = 4;

    public static function getIdFromString(string $channel): ?int
    {
        return match (strtoupper($channel)) {
            'MAIL' => self::MAIL->value,
            'PUSH' => self::PUSH->value,
            'REALTIME' => self::REALTIME->value,
            'SMS' => self::SMS->value,
            default => throw new ValidationException('Invalid channel ' . $channel),
        };
    }
}
