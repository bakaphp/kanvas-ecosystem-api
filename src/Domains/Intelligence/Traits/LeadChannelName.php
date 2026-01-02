<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Traits;

trait LeadChannelName
{
    public function getLeadChannelName(string $channelSlug): string
    {
        return match (true) {
            str_contains($channelSlug, 'wa-chat') => 'whatsapp',
            str_contains($channelSlug, 'twilio') => 'sms',
            str_contains($channelSlug, 'email') => 'email',
            default => 'unknown',
        };
    }
}
