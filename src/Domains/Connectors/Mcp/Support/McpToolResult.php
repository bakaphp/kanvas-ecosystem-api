<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Support;

use JsonException;

/**
 * Reads the `content` list a `tools/call` answers with — `[{type: text, text: "..."}, ...]`.
 */
final class McpToolResult
{
    /**
     * @return list<string>
     */
    public static function texts(mixed $content): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => is_array($item) && is_string($item['text'] ?? null) ? $item['text'] : null,
            is_array($content) ? $content : []
        ), 'is_string'));
    }

    /**
     * Vendors that return structured data put it in a text item as a JSON object (Browser Use's
     * `get_session`); the first item that decodes to one is the payload.
     *
     * @return array<string, mixed>|null
     */
    public static function json(mixed $content): ?array
    {
        foreach (self::texts($content) as $text) {
            try {
                $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
