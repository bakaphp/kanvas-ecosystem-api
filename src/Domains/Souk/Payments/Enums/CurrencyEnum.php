<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Enums;

enum CurrencyEnum: string
{
    case DOP = 'DOP';
    case USD = 'USD';

    public function isoNumericCode(): string
    {
        return match ($this) {
            self::DOP => '214',
            self::USD => '840',
        };
    }
}
