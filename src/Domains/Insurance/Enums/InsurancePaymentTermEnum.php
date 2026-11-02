<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsurancePaymentTermEnum: string
{
    case ANNUAL = 'annual';
    case SEMIANNUAL = 'semiannual';
    case QUARTERLY = 'quarterly';
    case MONTHLY = 'monthly';

    public function installments(): int
    {
        return match ($this) {
            self::ANNUAL => 1,
            self::SEMIANNUAL => 2,
            self::QUARTERLY => 4,
            self::MONTHLY => 12,
        };
    }
}
