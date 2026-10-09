<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

enum CurrencyEnum: int
{
    case DOP = 1;
    case USD = 2;
    case EUR = 3;

    /**
     * ISO 4217, because QuoteResult::$currency is read by a graph that never sees
     * Humano's numbering.
     */
    public function isoCode(): string
    {
        return match ($this) {
            self::DOP => 'DOP',
            self::USD => 'USD',
            self::EUR => 'EUR',
        };
    }
}
