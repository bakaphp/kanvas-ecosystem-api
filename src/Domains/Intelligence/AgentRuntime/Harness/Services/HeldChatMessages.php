<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * What a person said while their checkout was still opening, held for the launch to hand over the moment
 * the agent can take it, instead of asking them to send it again. In the cache, not the row: it lives
 * for the minutes a checkout takes to open, and a lost entry costs one repeated message.
 */
class HeldChatMessages
{
    private const int TTL_SECONDS = 3600;

    /**
     * The launch and an arriving message each read the row and the held list, then act on what they saw.
     * Run both under this lock: unlocked, a message held as the launch flips the row to idle is lost, or
     * reaches the agent twice, and two sessions get started in one checkout.
     *
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    public static function handOver(AgentTaskSession $session, Closure $call): mixed
    {
        return Cache::lock(self::key($session) . ':handover', 30)->block(10, $call);
    }

    public static function hold(AgentTaskSession $session, string $message): void
    {
        Cache::put(
            self::key($session),
            [...self::peek($session), $message],
            self::TTL_SECONDS
        );
    }

    /**
     * @return list<string>
     */
    public static function take(AgentTaskSession $session): array
    {
        $held = self::peek($session);
        Cache::forget(self::key($session));

        return $held;
    }

    /**
     * @return list<string>
     */
    private static function peek(AgentTaskSession $session): array
    {
        $held = Cache::get(self::key($session));

        return is_array($held) ? array_values(array_filter($held, 'is_string')) : [];
    }

    private static function key(AgentTaskSession $session): string
    {
        return 'coding-chat-held:' . $session->getId();
    }
}
