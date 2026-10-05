<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Support;

use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Tools\ToolOutput;

/**
 * Reads what a `tools/call` answers with: a `ToolOutput` from the connector, or the raw
 * `content` list `[{type: text, text: "..."}, ...]` a transport hands back directly.
 */
final class McpToolResult
{
    /**
     * @return list<string>
     */
    public static function texts(mixed $content): array
    {
        if ($content instanceof ToolOutput) {
            return array_values(array_map(
                static fn (TextContent $block): string => (string) $block->getContent(),
                array_filter($content->getBlocks(), static fn (mixed $block): bool => $block instanceof TextContent),
            ));
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => is_array($item) && is_string($item['text'] ?? null) ? $item['text'] : null,
            is_array($content) ? $content : []
        ), 'is_string'));
    }

    /**
     * Structured data arrives as JSON inside a text item; the first that decodes to an object is the payload.
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
