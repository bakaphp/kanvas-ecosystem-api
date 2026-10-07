<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Redis;

/**
 * A stop request for a thread, kept in Redis because the request lands on a web worker and the turn
 * runs on a queue worker. The flag is checked by CancelsOnRequestMiddleware before every inference
 * and every tool call, and cleared by RunNeuronChatAction when the turn ends, cancelled or not, so a
 * stop that arrives after the reply cannot cancel the next turn. The TTL bounds a stop nobody consumed.
 */
final class AgentTurnCancellationService
{
    private const string PREFIX = 'kanvas:agent-turn:cancel:';

    private const int TTL_SECONDS = 300;

    public static function request(string $threadId): void
    {
        Redis::connection()->setex(self::PREFIX . $threadId, self::TTL_SECONDS, (string) time());
    }

    public static function isRequested(string $threadId): bool
    {
        return (bool) Redis::connection()->exists(self::PREFIX . $threadId);
    }

    public static function clear(string $threadId): void
    {
        Redis::connection()->del(self::PREFIX . $threadId);
    }
}
