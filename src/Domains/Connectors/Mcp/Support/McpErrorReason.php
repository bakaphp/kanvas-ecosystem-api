<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Support;

/**
 * The vendor's own reason for an error response, so a rejection says why instead of only a status.
 * Capped because it lands in the grant's last_error and in the UI.
 */
final class McpErrorReason
{
    private const int MAX_LENGTH = 300;

    public static function fromBody(string $body, string $wwwAuthenticate = ''): string
    {
        // A challenge (`Bearer error="insufficient_scope", scope="…"`) is the part an admin can act on.
        if (trim($wwwAuthenticate) !== '') {
            return self::cap($wwwAuthenticate);
        }

        $trimmed = ltrim($body);

        // JSON or SSE, possibly cut off by the caller's read cap: only an error field explains anything —
        // a JSON-RPC result, whole or half, does not.
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[') || str_starts_with($trimmed, 'event:') || str_starts_with($trimmed, 'data:')) {
            $decoded = json_decode($trimmed, true);

            return self::cap(is_array($decoded) ? (self::fromJson($decoded) ?? '') : '');
        }

        return self::cap(strip_tags($body));
    }

    /**
     * Google's `{error: {message}}`, OAuth's `error_description`, or a plain `message`/`error`.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function fromJson(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;

        return match (true) {
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            is_string($decoded['error_description'] ?? null) => $decoded['error_description'],
            is_string($decoded['message'] ?? null) => $decoded['message'],
            is_string($error) => $error,
            default => null,
        };
    }

    private static function cap(string $reason): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $reason)), 0, self::MAX_LENGTH);
    }
}
