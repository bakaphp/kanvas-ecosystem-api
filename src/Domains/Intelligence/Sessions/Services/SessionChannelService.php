<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Services;

class SessionChannelService
{
    public static function createCanalId(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "$id@s.whatsapp.net",
            'sms' => "+$id",
            'email' => $id,
        };
    }

    public static function createChannelSlug(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "wa-chat-$id-at-swhatsappnet",
            'sms' => "twilio-$id",
            'email' => "email-$id",
        };
    }
}
