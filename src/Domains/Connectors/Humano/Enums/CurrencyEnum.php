<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

enum CurrencyEnum: int
{
    case DOP = 1;
    case USD = 2;
    case EUR = 3;

    public function isoCode(): string
    {
        return match ($this) {
            self::DOP => 'DOP',
            self::USD => 'USD',
            self::EUR => 'EUR',
        };
    }
}
