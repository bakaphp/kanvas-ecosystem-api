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
        $channelMap = [
            'EMAIL' => 'mail',
            'MAIL' => 'mail',
            'PUSH' => OneSignalNotificationChannel::class,
            'EXPO' => ExpoChannel::class,
            'DATABASE' => KanvasDatabase::class,
            'SMS' => TwilioSmsChannel::class,
        ];

        $slug = strtoupper($slug);

        // Check if it's already a class name
        if (in_array($slug, $channelMap, true)) {
            return $slug;
        }

        // Check if it's a slug we know about
        if (isset($channelMap[$slug])) {
            return $channelMap[$slug];
        }

        throw new ValidationException('Invalid channel ' . $slug);
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
            'sms' => self::SMS->value,
            'expo' => self::EXPO->value,
            default => throw new ValidationException('Invalid channel ' . $class),
        };
    }
}
