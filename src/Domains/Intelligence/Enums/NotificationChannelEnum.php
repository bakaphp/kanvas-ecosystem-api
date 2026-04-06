<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum NotificationChannelEnum: string
{
    case SMS = 'sms';
    case EMAIL = 'email';
    case PUSH = 'push';
    case WHATSAPP = 'whatsapp';

    public static function getAvailableChannels(): array
    {
        return [
            self::SMS->value,
            self::EMAIL->value,
            self::PUSH->value,
        ];
    }

    public static function isSupported(string $channel): bool
    {
        $supported = [
            self::SMS->value,
            self::EMAIL->value,
            self::PUSH->value,
        ];

        return in_array($channel, $supported, true);
    }
}
