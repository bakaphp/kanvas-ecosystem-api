<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

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
        return BigDecimal::of($value);
    }

    public static function minorUnits(BigDecimal|string|int|float $amount, ?string $currency = 'DOP'): int
    {
        return (int) BigDecimal::of($amount)->toScale(self::scaleFor($currency), RoundingMode::HALF_UP)->getUnscaledValue()->toInt();
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
        return $amount->toScale(2, RoundingMode::HALF_UP);
    }
}
