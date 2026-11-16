<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Concerns;

use Kanvas\Currencies\Models\Currencies;

/**
 * A NetSuite transaction names its currency for display ("US Dollar"), not by ISO code, so an unknown
 * name falls back to the base currency.
 */
trait ResolvesNetSuiteCurrency
{
    protected function getCurrencyModel(string $currencyCode): Currencies
    {
        return Currencies::where('code', $currencyCode)->first() ?? Currencies::getBaseCurrency();
    }
}
