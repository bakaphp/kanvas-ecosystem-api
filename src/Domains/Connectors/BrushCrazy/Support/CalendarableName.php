<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

/**
 * Ports BrushCrazy's `Calendarable::isDropInPaint()` and `displayName()`.
 *
 * Drop-in sessions are the highest-volume row in the system and studios stored the name a dozen
 * ways ("Walk in & Paint 1-8pm", "Drop-N & Paint!", "Drop In Open Paint"). Both the booking-model
 * classification and the Event grouping key depend on recognising them as one thing, so the
 * regexes have to match the source exactly — diverging here silently splits one Event into many.
 */
final class CalendarableName
{
    private const DROP_IN_PATTERN = '/^(walk[\s-]*in|drop[\s-]*in|drop[\s-]*n)\s*(&|and|open|\+)?\s*paint\b/';

    public static function isDropInPaint(?string $name): bool
    {
        return (bool) preg_match(self::DROP_IN_PATTERN, mb_strtolower(trim($name ?? '')));
    }

    /**
     * Canonical customer-facing title. Any trailing detail (a time range, a "!") is preserved
     * verbatim after the canonical label, matching the source.
     */
    public static function displayName(?string $name): string
    {
        $name = trim($name ?? '');

        if (! self::isDropInPaint($name)) {
            return $name;
        }

        return 'Drop-In & Paint' . preg_replace(self::DROP_IN_PATTERN . 'i', '', $name);
    }
}
