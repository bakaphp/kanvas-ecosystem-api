<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Support;

/**
 * MCP tool names are chosen by the server; LLM providers are stricter than MCP is.
 *
 * Anthropic and OpenAI both require `^[a-zA-Z0-9_-]{1,64}$`, while MCP servers ship dots, colons and
 * slashes — and prefixing with the server name to keep two servers from colliding can push a legal
 * name past 64. Both problems are silent: the provider rejects the whole tool list, not the one tool.
 *
 * The mapping is deliberately lossy in one direction only. Anything that cannot round-trip is carried
 * by the name map on the connector, which is what `invokeTool()` translates back through — the server
 * must always be called by the name IT published.
 */
final class McpToolName
{
    public const int MAX_LENGTH = 64;

    private const string SEPARATOR = '__';

    /**
     * The name the model sees: `{prefix}__{remote}`, sanitised, and hash-truncated when too long so
     * two long names under the same prefix cannot collapse onto each other.
     */
    public static function format(string $prefix, string $remoteName): string
    {
        $name = self::sanitize($prefix) . self::SEPARATOR . self::sanitize($remoteName);

        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $suffix = '_' . substr(sha1($prefix . self::SEPARATOR . $remoteName), 0, 6);

        return substr($name, 0, self::MAX_LENGTH - strlen($suffix)) . $suffix;
    }

    private static function sanitize(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?? '';

        return trim($clean, '_') === '' ? 'tool' : $clean;
    }
}
