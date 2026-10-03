<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

/**
 * SQL for summing the per-turn `usage` JSON on agent_conversation_messages. Three writers spell the keys
 * differently: laravel/ai ≤ 0.11 (`prompt_tokens`/`completion_tokens`, cache tokens excluded from the
 * prompt count), laravel/ai 1.x (`input_tokens`/`output_tokens`, cache reads and writes INCLUDED in
 * `input_tokens`) and Neuron / the runtimes (`input_tokens`, `cache_read`). Pricing charges cache tokens
 * at their own rate, so the 1.x shape has them taken back out of the input count — only rows carrying
 * the `*_input_tokens` keys subtract anything.
 */
final class ConversationUsageSqlHelper
{
    public static function inputTokens(string $alias = 'm'): string
    {
        $uncached = sprintf(
            '%s - COALESCE(%s, 0) - COALESCE(%s, 0)',
            self::key($alias, 'input_tokens'),
            self::key($alias, 'cache_read_input_tokens'),
            self::key($alias, 'cache_write_input_tokens'),
        );

        return sprintf(
            'COALESCE(SUM(GREATEST(CAST(COALESCE(%s, %s) AS SIGNED), 0)), 0)',
            self::key($alias, 'prompt_tokens'),
            $uncached,
        );
    }

    public static function outputTokens(string $alias = 'm'): string
    {
        return self::sumOf($alias, 'completion_tokens', 'output_tokens');
    }

    public static function cacheReadTokens(string $alias = 'm'): string
    {
        return self::sumOf($alias, 'cache_read_input_tokens', 'cache_read');
    }

    public static function cacheWriteTokens(string $alias = 'm'): string
    {
        return self::sumOf($alias, 'cache_write_input_tokens', 'cache_write');
    }

    private static function sumOf(string $alias, string $first, string $second): string
    {
        return sprintf(
            'COALESCE(SUM(CAST(COALESCE(%s, %s, 0) AS UNSIGNED)), 0)',
            self::key($alias, $first),
            self::key($alias, $second),
        );
    }

    /**
     * JSON_EXTRACT hands a JSON `null` back as the JSON scalar, not SQL NULL, so COALESCE would keep it;
     * 1.x writes `null` for the cache counts a provider did not report.
     */
    private static function key(string $alias, string $key): string
    {
        return sprintf("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(%s.`usage`, '$.%s')), 'null')", $alias, $key);
    }
}
