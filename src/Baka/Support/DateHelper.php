<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Carbon;
use Throwable;

class DateHelper
{
    public static function detectDateFormat(string $dateString): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return 'Y-m-d';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
            return 'm/d/Y';
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateString)) {
            return 'm-d-Y';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $dateString)) {
            return 'm/d/y';
        }

        return null;
    }

    public static function normalizeDate(string $date, string $timezone = 'UTC', string $format = 'Y-m-d'): ?string
    {
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date, $timezone)->format($format);
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalizeTime(string $time, string $format = 'H:i:s'): ?string
    {
        if ($time === '') {
            return null;
        }

        try {
            return Carbon::parse($time)->format($format);
        } catch (Throwable) {
            return null;
        }
    }

    // Returns null instead of throwing on garbage / non-strings / empty input.
    public static function tryParseCarbon(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
