<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AllRatesToday;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\FxRates\Contracts\MarketRateProviderInterface;
use Kanvas\Scribe\FxRates\DataTransferObject\MarketRate;
use Override;
use RuntimeException;

/**
 * The live mid-market rate between any two currencies (`/api/v1/rates`), refreshed about once a minute. Same
 * platform-wide key as the central-bank provider, and every call spends one unit of its quota, an unknown pair
 * included, so callers go through MarketRateService and its cache.
 */
class AllRatesTodayMarketRateProvider implements MarketRateProviderInterface
{
    private const int TIMEOUT_SECONDS = 20;

    /**
     * An unknown code, or a thinly traded pair the upstream has nothing for at the moment.
     */
    private const array NO_QUOTE_STATUSES = [
        400,
        404,
        502,
    ];

    #[Override]
    public function latestRate(string $source, string $target): ?MarketRate
    {
        $key = Str::trimToNull((string) config('services.allratestoday.key'))
            ?? throw new ValidationException('The allratestoday API key is not configured (ALLRATESTODAY_API_KEY).');

        $source = strtoupper($source);
        $target = strtoupper($target);

        $response = Http::baseUrl(AllRatesTodayCentralBankRateProvider::BASE_URL)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withToken($key)
            ->get('api/v1/rates', [
                'source' => $source,
                'target' => $target,
            ]);

        if (in_array($response->status(), self::NO_QUOTE_STATUSES, true)) {
            return null;
        }

        if (! $response->successful()) {
            $error = mb_substr((string) $response->json('error'), 0, 200);

            throw new RuntimeException("allratestoday answered HTTP {$response->status()}: {$error}");
        }

        // Always a list, one row per target, even for a single pair.
        $rate = $response->json('0.rate');
        $observedAt = $response->json('0.time');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return new MarketRate(
            source: $source,
            target: $target,
            rate: (float) $rate,
            observedAt: is_string($observedAt) ? Carbon::parse($observedAt) : Carbon::now(),
            provider: AllRatesTodayCentralBankRateProvider::PROVIDER,
        );
    }
}
