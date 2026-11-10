<?php

declare(strict_types=1);

namespace Baka\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    private const array MINOR_UNIT_SCALE = [
        'BIF' => 0,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'MGA' => 0,
        'PYG' => 0,
        'RWF' => 0,
        'UGX' => 0,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
        'BHD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'OMR' => 3,
        'TND' => 3,
    ];

    public static function of(string|int|float $value): BigDecimal
    {
        return self::decimal($value);
    }

    public static function minorUnits(BigDecimal|string|int|float $amount, ?string $currency = 'DOP'): int
    {
        return (int) self::decimal($amount)
            ->toScale(
                self::scaleFor($currency),
                RoundingMode::HalfUp
            )
            ->getUnscaledValue()->toInt();
    }

    public static function fromMinorUnits(int $minor, ?string $currency = 'DOP'): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, self::scaleFor($currency));
    }

    private static function scaleFor(?string $currency): int
    {
        return self::MINOR_UNIT_SCALE[strtoupper($currency ?? 'DOP')] ?? 2;
    }

    public static function roundCollect(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale(2, RoundingMode::HalfUp);
    }

    public static function sum(BigDecimal|string|int|float ...$values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::decimal($value));
        }

        return $total;
    }

    /**
     * Money columns are DECIMAL(18,4). Rounding here, instead of letting MySQL round each column on insert,
     * is what keeps a total equal to the sum of its parts once both are stored.
     */
    public static function stored(BigDecimal|string|int|float $amount): float
    {
        return self::decimal($amount)->toScale(4, RoundingMode::HalfUp)->toFloat();
    }

    public static function cents(BigDecimal|string|int|float $amount): float
    {
        return self::decimal($amount)->toScale(2, RoundingMode::HalfUp)->toFloat();
    }

    public static function toBase(BigDecimal|string|int|float $native, BigDecimal|string|int|float $fxRate): float
    {
        return self::stored(self::decimal($native)->multipliedBy(self::decimal($fxRate)));
    }

    /**
     * brick/math 0.15 drops float input; a float goes through its string form, which is what 0.14 did itself.
     */
    private static function decimal(BigDecimal|string|int|float $value): BigDecimal
    {
        return BigDecimal::of(is_float($value) ? (string) $value : $value);
    }
}
