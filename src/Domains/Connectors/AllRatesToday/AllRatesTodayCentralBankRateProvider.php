<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AllRatesToday;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\FxRates\Contracts\CentralBankRateProviderInterface;
use Kanvas\Scribe\FxRates\DataTransferObject\CentralBankRate;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use RuntimeException;

/**
 * Central banks' published tables, relayed by allratestoday.com (`/api/v1/central-bank/{bank}/latest`); the bank
 * code and rate type come from the country's fiscal regime, so a new country adds no code here. The free tier
 * is a lifetime quota and dated lookups are paid, so the daily sync stores each day's rate as it comes; production
 * needs a paid key. The key is platform-wide, from
 * config/services.php, and only ever sent as a Bearer header.
 */
class AllRatesTodayCentralBankRateProvider implements CentralBankRateProviderInterface
{
    public const string BASE_URL = 'https://allratestoday.com';
    public const string PROVIDER = 'allratestoday';
    private const int TIMEOUT_SECONDS = 20;

    public function latestRate(OfficialRateSource $source, string $currency): ?CentralBankRate
    {
        $key = Str::trimToNull((string) config('services.allratestoday.key'))
            ?? throw new ValidationException('The allratestoday API key is not configured (ALLRATESTODAY_API_KEY).');

        $response = Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withToken($key)
            ->get('api/v1/central-bank/' . rawurlencode($source->bank) . '/latest', [
                'source' => strtoupper($currency),
                'target' => $source->localCurrency,
                'type' => $source->type,
            ]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("allratestoday answered HTTP {$response->status()}: " . mb_substr((string) $response->json('error'), 0, 200));
        }

        $rate = $response->json('rate');
        $rateDate = $response->json('rate_date');

        // The tax authority names one rate (DGII: buy); a provider that answers with another type is not used.
        if ($response->json('rate_type') !== $source->type || ! is_numeric($rate) || ! is_string($rateDate)) {
            return null;
        }

        return new CentralBankRate(
            source: strtoupper($currency),
            target: $source->localCurrency,
            rate: (string) $rate,
            rateDate: Carbon::parse($rateDate),
            provider: self::PROVIDER,
            type: $source->type,
        );
    }
}
