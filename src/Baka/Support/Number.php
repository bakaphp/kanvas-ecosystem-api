<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Number as IlluminateNumber;

/**
 * The one Number the codebase imports: Laravel's helpers plus ours. toFloat() and friends parse
 * typed and catalog numbers by hand because Laravel's parse()/parseFloat() do not read "20.000"
 * or "45k" the way a shopper writes them.
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

    /**
     * Null, not zero, when there is nothing to divide by: "0 of 0" is no rate at all, and a
     * report that prints 0% for it is lying.
     */
    public static function ratio(int|float $part, int|float $whole, int $precision = 4): ?float
    {
        return $whole > 0 ? round($part / $whole, $precision) : null;
    }

    /**
     * "1,234.50". Fixed en-US grouping whatever locale Number is set to: this text goes into
     * emails, PDFs and agent prompts that must read the same for every tenant.
     */
    public static function grouped(int|float $number, int $precision = 0): string
    {
        return number_format($number, $precision);
    }

    /** "1234.50", no grouping: the form payloads, signatures and dedupe keys are compared in. */
    public static function fixed(int|float $number, int $precision = 2): string
    {
        return number_format($number, $precision, '.', '');
    }

    /**
     * "$1,234.50 USD": the symbol currency() prints plus the code. The symbol alone is not enough,
     * "$" is also how pesos are written, so a tenant that bills in DOP and USD could not tell the
     * two apart. A currency with no symbol in this locale already prints as its code ("DOP 1,234.50").
     *
     * Rounded here first: ICU rounds half to even (0.125 → 0.12), money is displayed half-up.
     */
    public static function withCurrencyCode(int|float $amount, string $currency, int $precision = 2): string
    {
        $formatted = str_replace("\u{00A0}", ' ', (string) self::currency(round($amount, $precision), $currency, precision: $precision));

        return str_contains($formatted, $currency) ? $formatted : $formatted . ' ' . $currency;
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
