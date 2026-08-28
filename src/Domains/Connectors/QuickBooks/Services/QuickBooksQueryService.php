<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Services;

use Kanvas\Exceptions\ValidationException;

/**
 * The QuickBooks SDK's DataService::Query() takes a raw IDS query string — there are no bound
 * parameters — so every interpolated value has to be escaped here before it reaches the query.
 * IDS escapes a literal with a backslash, and has no escape at all for control characters.
 */
final class QuickBooksQueryService
{
    public static function escapeString(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value) ?? '';

        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * QuickBooks entity ids are opaque tokens assigned by Intuit; anything outside that shape
     * means the id came from somewhere it shouldn't have.
     */
    public static function escapeId(?string $value): string
    {
        $value = (string) $value;

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) {
            throw new ValidationException('Invalid QuickBooks entity id');
        }

        return $value;
    }
}
