<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;

enum NotificationChannelEnum: int
{
    case MAIL = 1;
    case PUSH = 2;
    case REALTIME = 3;
    case SMS = 4;
    case DATABASE = 5;

    public static function getIdFromString(string $channel): ?int
    {
        return match (strtoupper($channel)) {
            'MAIL' => self::MAIL->value,
            'PUSH' => self::PUSH->value,
            'REALTIME' => self::REALTIME->value,
            'SMS' => self::SMS->value,
            'DATABASE' => self::DATABASE->value,
            default => throw new ValidationException('Invalid channel ' . $channel),
        };
    }

    public static function getNotificationChannelBySlug(string $slug): ?string
    {
        return match (strtoupper($slug)) {
            'MAIL' => 'mail',
            'PUSH' => OneSignalNotificationChannel::class,
            'DATABASE' => KanvasDatabase::class,
            default => throw new ValidationException('Invalid channel ' . $slug),
        };
    }
}
