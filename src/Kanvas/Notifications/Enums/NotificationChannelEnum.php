<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use NotificationChannels\Expo\ExpoChannel;

enum NotificationChannelEnum: int
{
    case MAIL = 1;
    case PUSH = 2;
    case DATABASE = 3;
    case REALTIME = 4;
    case SMS = 5;
    case EXPO = 6;
    case BROADCAST = 7;

    public static function getIdFromString(string $channel): ?int
    {
        return match (strtoupper($channel)) {
            'MAIL' => self::MAIL->value,
            'PUSH' => self::PUSH->value,
            'REALTIME' => self::REALTIME->value,
            'SMS' => self::SMS->value,
            'DATABASE' => self::DATABASE->value,
            'EXPO' => self::EXPO->value,
            default => throw new ValidationException('Invalid channel ' . $channel),
        };
    }

    public static function getNotificationChannelBySlug(string $slug): ?string
    {
        return match (strtoupper($slug)) {
            'EMAIL' => 'mail',
            'MAIL' => 'mail',
            'PUSH' => OneSignalNotificationChannel::class,
            'EXPO' => ExpoChannel::class,
            'DATABASE' => KanvasDatabase::class,
            'SMS' => TwilioSmsChannel::class,
            default => throw new ValidationException('Invalid channel ' . $slug),
        };
    }

    public static function getChannelIdByClassReference(string $class): ?int
    {
        return match ($class) {
            'mail' => self::MAIL->value,
            'push' => self::PUSH->value,
            OneSignalNotificationChannel::class => self::PUSH->value,
            ExpoChannel::class => self::EXPO->value,
            KanvasDatabase::class => self::DATABASE->value,
            'database' => self::DATABASE->value,
            TwilioSmsChannel::class => self::SMS->value,
            'broadcast' => self::BROADCAST->value,
            default => throw new ValidationException('Invalid channel ' . $class),
        };
    }
}
