<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

enum EcfItbisRateEnum: string
{
    case EXEMPT = 'exempt';
    case RATE_18 = '18';
    case RATE_16 = '16';
    case RATE_0 = '0';

    /**
     * DGII's IndicadorFacturacion on a line: 1/2/3 the ITBIS rates, 4 exempt. 0 (not billable, outside the totals)
     * and anything else map to no rate.
     */
    public static function fromIndicator(int $indicator): ?self
    {
        foreach (self::cases() as $rate) {
            if ($rate->indicator() === $indicator) {
                return $rate;
            }
        }

        return null;
    }

    public function indicator(): int
    {
        return match ($this) {
            self::RATE_18 => 1,
            self::RATE_16 => 2,
            self::RATE_0 => 3,
            self::EXEMPT => 4,
        };
    }

    public function percent(): float
    {
        return match ($this) {
            self::RATE_18 => 18.0,
            self::RATE_16 => 16.0,
            self::EXEMPT, self::RATE_0 => 0.0,
        };
    }
}
