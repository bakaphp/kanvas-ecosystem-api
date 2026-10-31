<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    private const array MINOR_UNIT_SCALE = [
        'JPY' => 0,
        'KWD' => 3,
        'BHD' => 3,
        'OMR' => 3,
    ];

    public static function of(string|int $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    public static function minorUnits(BigDecimal $amount, string $currency = 'DOP'): int
    {
        $scale = self::MINOR_UNIT_SCALE[strtoupper($currency)] ?? 2;

        return (int) $amount->toScale($scale, RoundingMode::HALF_UP)->getUnscaledValue()->toInt();
    }

    public static function roundCollect(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale(2, RoundingMode::HALF_UP);
    }

    public static function allocate(BigDecimal $total, array $weights, ?BigDecimal $subtotalCap = null): array
    {
        $count = count($weights);

        if ($count === 0) {
            return [];
        }

        $weightSum = BigDecimal::zero();
        foreach ($weights as $w) {
            $weightSum = $weightSum->plus(BigDecimal::of($w));
        }

        $scale = 2;
        $allZero = $weightSum->isZero();

        if ($allZero) {
            $base = $total->toScale($scale, RoundingMode::DOWN)->dividedBy($count, $scale, RoundingMode::DOWN);
            $remainder = $total->toScale($scale, RoundingMode::DOWN)->minus($base->multipliedBy($count));

            $remainderCents = (int) $remainder->toScale($scale, RoundingMode::DOWN)->getUnscaledValue()->toInt();
            $unit = BigDecimal::ofUnscaledValue(1, $scale);

            $allocations = array_fill(0, $count, $base);
            for ($i = 0; $i < $remainderCents; $i++) {
                $allocations[$i] = $allocations[$i]->plus($unit);
            }

            if ($subtotalCap !== null) {
                foreach ($allocations as &$a) {
                    if ($a->compareTo($subtotalCap) > 0) {
                        $a = $subtotalCap->toScale($scale, RoundingMode::HALF_UP);
                    }
                }
                unset($a);
            }

            return $allocations;
        }

        $floors = [];
        $remainders = [];

        foreach ($weights as $i => $w) {
            $share = $total->multipliedBy(BigDecimal::of($w))->dividedBy($weightSum, $scale + 4, RoundingMode::DOWN);
            $floor = $share->toScale($scale, RoundingMode::DOWN);
            $floors[$i] = $floor;
            $remainders[$i] = $share->minus($floor);
        }

        $distributed = BigDecimal::zero();
        foreach ($floors as $f) {
            $distributed = $distributed->plus($f);
        }

        $remainderCents = (int) $total->toScale($scale, RoundingMode::DOWN)
            ->minus($distributed->toScale($scale, RoundingMode::DOWN))
            ->toScale($scale, RoundingMode::DOWN)
            ->getUnscaledValue()
            ->toInt();

        arsort($remainders);

        $unit = BigDecimal::ofUnscaledValue(1, $scale);
        $indices = array_keys($remainders);

        for ($i = 0; $i < $remainderCents; $i++) {
            $idx = $indices[$i % count($indices)];
            $floors[$idx] = $floors[$idx]->plus($unit);
        }

        if ($subtotalCap !== null) {
            foreach ($floors as &$f) {
                if ($f->compareTo($subtotalCap) > 0) {
                    $f = $subtotalCap->toScale($scale, RoundingMode::HALF_UP);
                }
            }
            unset($f);
        }

        return array_values($floors);
    }
}
