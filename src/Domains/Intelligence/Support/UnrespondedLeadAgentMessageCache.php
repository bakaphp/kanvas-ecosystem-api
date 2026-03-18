<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class UnrespondedLeadAgentMessageCache
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
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            $pattern = "*unresponded_agent_message:{$lead->getId()}:*";

            $redis = $store->connection();
            $keys = $redis->keys($pattern);

            if (empty($keys)) {
                return false;
            }

            $deletedCount = 0;
            foreach ($keys as $fullKey) {
                $pos = strpos($fullKey, 'unresponded_agent_message');
                if ($pos !== false) {
                    $cacheKey = substr($fullKey, $pos);
                    if (Cache::forget($cacheKey)) {
                        $deletedCount++;
                    }
                }
            }

            return $deletedCount > 0;
        }

        if ($store instanceof ArrayStore) {
            $prefix = "unresponded_agent_message:{$lead->getId()}:";
            $deletedCount = 0;

            foreach (Channel::all() as $channelItem) {
                $key = "{$prefix}{$channelItem->getId()}";
                if (Cache::has($key) && Cache::forget($key)) {
                    $deletedCount++;
                }
            }

            return $deletedCount > 0;
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
