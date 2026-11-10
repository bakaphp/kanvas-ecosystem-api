<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Actions;

use Baka\Support\AllowedClass;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\FxRates\Contracts\CentralBankRateProviderInterface;
use Kanvas\Scribe\FxRates\DataTransferObject\CentralBankRate;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use Kanvas\Scribe\FxRates\Models\FxRate;

/**
 * Stores, for every country with a fiscal regime, the official rate of each foreign currency we invoice in
 * (`scribe.fx_currencies`), as platform rows (apps_id 0): the official rate is the same for every tenant. The
 * regime names the bank and the rate type. Keyed by the date the bank published it, not today, so running twice
 * (or on a weekend, when the provider returns Friday's table) changes nothing.
 */
class SyncCentralBankRatesAction
{
    public function __construct(
        private readonly ?CentralBankRateProviderInterface $provider = null,
    ) {
    }

    /**
     * @return list<FxRate>
     */
    public function execute(): array
    {
        $provider = $this->provider ?? self::configuredProvider();
        $stored = [];

        foreach (FiscalRegimes::all() as $regime) {
            $source = $regime->officialRateSource();
            if ($source === null) {
                continue;
            }

            foreach ((array) config('scribe.fx_currencies', ['USD']) as $currency) {
                if (strtoupper((string) $currency) === $source->localCurrency) {
                    continue;
                }

                $rate = $provider->latestRate($source, (string) $currency);
                if ($rate !== null && (float) $rate->rate > 0) {
                    $stored[] = $this->store($rate, $source);
                }
            }
        }

        return $stored;
    }

    public static function configuredProvider(): CentralBankRateProviderInterface
    {
        $class = AllowedClass::resolve(config('scribe.fx_rate_provider'), CentralBankRateProviderInterface::class)
            ?? throw new ValidationException('No central bank rate provider is configured (scribe.fx_rate_provider).');

        return new $class();
    }

    private function store(CentralBankRate $rate, OfficialRateSource $source): FxRate
    {
        return FxRate::query()->updateOrCreate(
            [
                'apps_id' => 0,
                'base_currency' => $rate->source,
                'quote_currency' => $rate->target,
                'rate_date' => $rate->rateDate->toDateString(),
            ],
            [
                'rate' => $rate->rate,
                'source' => $source->sourceLabel(),
                'metadata' => ['type' => $rate->type, 'provider' => $rate->provider],
            ]
        );
    }
}
