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

        if (str_starts_with($trimmed, 'event:') || str_starts_with($trimmed, 'data:')) {
            preg_match_all('/^data:\s?(.*)$/m', $trimmed, $data);
            $trimmed = implode('', $data[1]);
        }

        // JSON, possibly cut off by the caller's read cap: only an error explains anything — a successful
        // JSON-RPC result, whole or half, does not.
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            return self::cap(is_array($decoded) ? (self::fromJson($decoded) ?? '') : '');
        }

        return self::cap(strip_tags($trimmed));
    }

    /**
     * Google's `{error: {message}}`, OAuth's `error_description`, a plain `message`/`error`, or a tool result
     * flagged `isError` — Google Calendar answers a bad argument with HTTP 400 carrying one, and its text
     * is the only place the server says which argument.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function fromJson(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        $result = $decoded['result'] ?? null;

        return match (true) {
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            is_string($decoded['error_description'] ?? null) => $decoded['error_description'],
            is_string($decoded['message'] ?? null) => $decoded['message'],
            is_string($error) => $error,
            is_array($result) && ($result['isError'] ?? false) === true => self::resultText($result),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private static function resultText(array $result): ?string
    {
        $texts = array_filter(array_map(
            fn (mixed $content): ?string => is_array($content) && is_string($content['text'] ?? null) ? $content['text'] : null,
            (array) ($result['content'] ?? [])
        ), 'is_string');

        return $texts === [] ? null : implode(' ', $texts);
    }

    private static function cap(string $reason): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $reason)), 0, self::MAX_LENGTH);
    }
}
