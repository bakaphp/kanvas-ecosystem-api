<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Support;

use Illuminate\Support\Facades\Cache;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class UnrespondedAgentMessageCache
{
    public static function generateKey(Lead $lead, Channel $channel): string
    {
        return "unresponded_agent_message:{$lead->getId()}:{$channel->getId()}";
    }

    public static function exists(Lead $lead, Channel $channel): bool
    {
        return Cache::has(self::generateKey($lead, $channel));
    }

    public static function clear(Lead $lead, Channel $channel): bool
    {
        $key = self::generateKey($lead, $channel);
        if (Cache::has($key)) {
            return Cache::forget($key);
        }

        return false;
    }

    public static function set(Lead $lead, Channel $channel, Message $message, int $ttlMinutes): bool
    {
        return Cache::put(
            self::generateKey($lead, $channel),
            [
                'message_id' => $message->getId(),
                'channel_id' => $channel->getId(),
                'lead_id' => $lead->getId(),
                'dispatched_at' => now()->toIso8601String(),
            ],
            now()->addMinutes($ttlMinutes)
        );
    }

    public static function get(Lead $lead, Channel $channel): ?array
    {
        return Cache::get(self::generateKey($lead, $channel));
    }
}
