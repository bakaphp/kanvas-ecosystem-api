<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Number as IlluminateNumber;

/**
 * Typed and catalog numbers → floats, without `intl`. Laravel's parse()/parseFloat()
 * hard-require the extension, which this image lacks, so calling them throws.
 */
class Number extends IlluminateNumber
{
    /** Suffix → multiplier. "mil" is Spanish for thousand, never "million". */
    public const array MULTIPLIERS = [
        'k' => 1000,
        'thousand' => 1000,
        'mil' => 1000,
    ];

    /**
     * Assumes en-US grouping ("1,299.99") except for a dot followed by exactly
     * three digits ("20.000"), which no price writes as decimals but every
     * Spanish-speaking shopper writes as thousands.
     */
    public static function toFloat(string $raw): ?float
    {
        $raw = rtrim(trim($raw), '.,');

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
        }

        $cleaned = str_replace(',', '', $raw);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    public static function applyMultiplier(float $value, ?string $suffix): float
    {
        return $value * (float) (self::MULTIPLIERS[strtolower((string) $suffix)] ?? 1);
    }

    /** MULTIPLIERS as a regex alternation, so no pattern matches a suffix applyMultiplier() ignores. */
    public static function multiplierPattern(): string
    {
        return implode('|', array_map('preg_quote', array_keys(self::MULTIPLIERS)));
    }

    /**
     * "62,000 mi", "45k km", "2019" → [value, unit]. The unit is whatever word
     * follows the number, lowercased; the caller decides whether it knows it.
     *
     * @return array{value: float, unit: string|null}|null
     */
    public static function parseMeasurement(string $raw): ?array
    {
        $pattern = '/(\d[\d.,]*)\s*(' . self::multiplierPattern() . ')?(?![\p{L}\d])\s*([\p{L}]+)?/iu';

        if (preg_match($pattern, trim($raw), $matches) !== 1) {
            return null;
        }

        $value = self::toFloat($matches[1]);

        if ($value === null) {
            return null;
        }

        $unit = isset($matches[3]) && $matches[3] !== '' ? mb_strtolower($matches[3]) : null;

        return [
            'value' => self::applyMultiplier($value, $matches[2] ?? null),
            'unit' => $unit,
        ];
    }
}
