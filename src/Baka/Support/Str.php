<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Str as IlluminateStr;

class Str extends IlluminateStr
{
    public const int BROADCAST_CHANNEL_MAX_LENGTH = 164;

    /**
     * Given a string remove all any special characters.
     */
    public static function cleanup(string $string): string
    {
        return preg_replace("/[^a-zA-Z0-9_\s]/", '', $string);
    }

    /**
     * Given a json string decode it into array.
     */
    public static function jsonToArray(mixed $string): mixed
    {
        return is_string($string) && self::isJson($string) ? json_decode($string, true) : $string;
    }

    /**
     * Generate none-unicode slugs for simple parsing.
     */
    public static function simpleSlug(string $string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    }

    /**
     * Strip all non-digit characters from a string.
     * Useful for normalizing phone numbers, EINs, SSNs, ZIPs, etc.
     */
    public static function digitsOnly(?string $value): string
    {
        return $value === null ? '' : ((string) preg_replace('/\D+/', '', $value));
    }

    public static function sanitizePhoneNumber(?string $phone = null): string
    {
        return self::digitsOnly($phone);
    }

    public static function cleanJsonString(string $json): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($json));
    }

    public static function normalizePhoneNumber(string $phone): string
    {
        return (string) preg_replace('/^\+?1/', '', $phone);
    }

    /**
     * Ensure a phone number has the international '+' prefix (E.164 format) for API calls.
     */
    public static function ensurePhonePrefix(string $phone): string
    {
        $phone = ltrim($phone);

        if (! self::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Convert a phone number to strict E.164 (digits-only with leading '+').
     * Assumes 10-digit numbers belong to the given default country code (US/DR by default).
     */
    public static function toE164(?string $phone, string $defaultCountryCode = '1'): string
    {
        $digits = self::digitsOnly($phone);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+' . $defaultCountryCode . $digits;
        }

        return '+' . $digits;
    }

    /**
     * Trim a value, treating a blank result as absent. Prefer this over `trim($x) ?: null`, which is
     * falsy-based and so turns the meaningful string "0" into null.
     */
    public static function trimToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * `trimToNull` for a value of unknown type — a decoded JSON field, an `integrations.metadata` key, a
     * custom field, a GraphQL argument.
     *
     * Not `ScalarCoercionTrait::stringOrNull`, which casts whatever it is given and does not trim. Here a
     * non-string is absent rather than cast, so an array or an int never becomes a string that a caller
     * then reads as a URL or a token.
     */
    public static function trimmedStringOrNull(mixed $value): ?string
    {
        return self::trimToNull(is_string($value) ? $value : null);
    }

    /**
     * Rich-text stored by an editor, flattened for somewhere that cannot render it — an LLM prompt, a
     * plain-text email, a log line.
     *
     * Block tags become newlines BEFORE the strip, because `strip_tags` alone welds neighbouring
     * blocks together: `<p>Santo Domingo.</p><p>Overview</p>` comes out `Santo Domingo.Overview`.
     *
     * Link text survives, the href does not: an editor writes
     * `<a target="_blank" rel="noopener noreferrer nofollow" href="...">1</a>` for a one-character
     * link, so flattening before any truncation is what stops the budget going on attributes.
     */
    public static function htmlToText(?string $html): string
    {
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", (string) $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the runs of blank lines the block substitution leaves behind, and the non-breaking
        // spaces editors emit, which survive as U+00A0 and read as stray characters downstream.
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim((string) preg_replace('/\n\s*\n\s*\n+/', "\n\n", (string) $text));
    }

    public static function sanitizeEmail(string $email): string
    {
        return str_replace(['@', '.'], ['-at-', '-dot-'], $email);
    }

    /**
     * Pusher throws `Invalid channel name` on any character outside `[A-Za-z0-9_\-=@,.;]` or a name
     * over 164 chars, so a channel name built from a slug or an email must pass through here first.
     */
    public static function sanitizeChannelName(string $name): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_\-=@,.;]/', '-', $name);

        if (strlen($sanitized) <= self::BROADCAST_CHANNEL_MAX_LENGTH) {
            return $sanitized;
        }

        // Hash the truncated tail so two long names sharing a prefix don't collapse onto the
        // same channel and leak each other's payloads.
        $suffix = '-' . substr(sha1($sanitized), 0, 8);

        return substr($sanitized, 0, self::BROADCAST_CHANNEL_MAX_LENGTH - strlen($suffix)) . $suffix;
    }

    /**
     * Split a full name string into firstname and lastname.
     * If firstname or lastname are already provided, returns them as-is.
     *
     * @return array{firstname: string, lastname: string}
     */
    public static function parseFullName(
        string $fullName,
        string $firstname = '',
        string $lastname = ''
    ): array {
        if ($firstname !== '' || $lastname !== '' || $fullName === '') {
            return ['firstname' => $firstname, 'lastname' => $lastname];
        }

        $parts = explode(' ', $fullName, 2);

        return [
            'firstname' => $parts[0],
            'lastname' => $parts[1] ?? '',
        ];
    }
}
