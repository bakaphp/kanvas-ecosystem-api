<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

/**
 * Parses a rendered push-notification template body into title/message/subtitle.
 *
 * Historical templates store a single JSON-shaped string with Blade placeholders:
 * `{"title":"...","message":"...","subtitle":"..."}`. When a placeholder expands
 * to a value containing `"` (or when the template author wrapped a placeholder in
 * literal quotes), the rendered string is no longer valid JSON. `parseStrict`
 * catches that; `parseLenient` recovers the fields by walking sibling-key
 * boundaries, so the push still ships instead of erroring out the queue worker.
 */
class PushTemplateParser
{
    /**
     * @return array{title: string, message: string, subtitle: string|null}|null
     */
    public static function parseStrict(string $rendered): ?array
    {
        $clean = self::clean($rendered);

        if ($clean === '') {
            return null;
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return [
            'title' => (string) ($decoded['title'] ?? ''),
            'message' => (string) ($decoded['message'] ?? ''),
            'subtitle' => isset($decoded['subtitle']) ? (string) $decoded['subtitle'] : null,
        ];
    }

    /**
     * @return array{title: string, message: string, subtitle: string|null}
     */
    public static function parseLenient(string $rendered): array
    {
        $clean = self::clean($rendered);

        return [
            'title' => self::extractField($clean, 'title') ?? '',
            'message' => self::extractField($clean, 'message') ?? '',
            'subtitle' => self::extractField($clean, 'subtitle'),
        ];
    }

    private static function clean(string $rendered): string
    {
        $collapsed = (string) preg_replace('/\s+/', ' ', trim($rendered));

        return self::repairInvalidEscapes($collapsed);
    }

    /**
     * Drop backslashes that don't form a valid JSON escape sequence.
     *
     * Placeholder values pass through addslashes-style escaping before Blade
     * HTML-escapes them, so a name like `Man's` becomes `Man\&#039;s` in the
     * rendered template — a stray `\&` that makes the whole string invalid JSON.
     * Valid escapes (\" \\ \/ \b \f \n \r \t \uXXXX) are left untouched.
     */
    private static function repairInvalidEscapes(string $raw): string
    {
        return (string) preg_replace('/\\\\(?!["\\\\\/bfnrtu])/', '', $raw);
    }

    private static function extractField(string $raw, string $key): ?string
    {
        $keyPattern = '/"' . preg_quote($key, '/') . '"\s*:\s*/';

        if (! preg_match($keyPattern, $raw, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $valueStart = $match[0][1] + strlen($match[0][0]);
        $remaining = substr($raw, $valueStart);

        $boundaries = [];

        foreach (['title', 'message', 'subtitle'] as $sibling) {
            if ($sibling === $key) {
                continue;
            }

            $siblingPattern = '/,\s*"' . preg_quote($sibling, '/') . '"\s*:/';

            if (preg_match($siblingPattern, $remaining, $siblingMatch, PREG_OFFSET_CAPTURE)) {
                $boundaries[] = $siblingMatch[0][1];
            }
        }

        if (preg_match('/\}\s*$/', $remaining, $closingMatch, PREG_OFFSET_CAPTURE)) {
            $boundaries[] = $closingMatch[0][1];
        }

        $valueEnd = empty($boundaries) ? strlen($remaining) : min($boundaries);
        $value = trim(substr($remaining, 0, $valueEnd));

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
