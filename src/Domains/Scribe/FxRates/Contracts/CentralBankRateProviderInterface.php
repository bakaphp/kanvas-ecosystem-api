<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Contracts;

use Kanvas\Scribe\FxRates\DataTransferObject\CentralBankRate;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;

/**
 * Where the official daily rates come from, for any country's central bank. Registered in config/scribe.php
 * (`fx_rate_provider`), so Scribe never names the service behind it.
 */
interface CentralBankRateProviderInterface
{
    /**
     * The most recent rate of the source's type that its central bank published for one unit of $currency in the
     * source's local currency, or null when the provider has none.
     */
    public function latestRate(OfficialRateSource $source, string $currency): ?CentralBankRate;
}
