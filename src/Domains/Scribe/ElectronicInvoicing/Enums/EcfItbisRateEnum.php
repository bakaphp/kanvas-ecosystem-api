<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum EcfItbisRateEnum: string
{
    case EXEMPT = 'exempt';
    case RATE_18 = '18';
    case RATE_16 = '16';
    case RATE_0 = '0';

    public function percent(): float
    {
        return match ($this) {
            self::RATE_18 => 18.0,
            self::RATE_16 => 16.0,
            self::EXEMPT, self::RATE_0 => 0.0,
        };
    }
}
