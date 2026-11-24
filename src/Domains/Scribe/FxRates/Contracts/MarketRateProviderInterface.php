<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Contracts;

use Kanvas\Scribe\FxRates\DataTransferObject\MarketRate;

/**
 * Where a live market rate between any two currencies comes from. Registered in config/scribe.php
 * (`market_rate_provider`), so Scribe never names the service behind it.
 */
interface MarketRateProviderInterface
{
    /**
     * The current rate for one unit of $source in $target, or null when the provider has no quote for the pair.
     */
    public function latestRate(string $source, string $target): ?MarketRate;
}
