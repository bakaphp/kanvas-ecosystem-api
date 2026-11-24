<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Services;

use Baka\Support\AllowedClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\FxRates\Contracts\MarketRateProviderInterface;
use Kanvas\Scribe\FxRates\DataTransferObject\MarketRate;

/**
 * Cached, never written to `fx_rates`: that table is what a fiscal document freezes its declared rate from, and
 * it does not look at a row's source, so a market row there would be declared to the tax authority as the
 * official one. The cache also holds a pair with no quote, because the provider charges for that call too.
 */
class MarketRateService
{
    private const int CACHE_SECONDS = 600;
    private const string CACHE_PREFIX = 'scribe:market-rate:';

    public function latest(string $source, string $target): ?MarketRate
    {
        $source = strtoupper($source);
        $target = strtoupper($target);

        $cached = Cache::remember(
            self::CACHE_PREFIX . "{$source}:{$target}",
            self::CACHE_SECONDS,
            function () use ($source, $target): array {
                $rate = $this->configuredProvider()->latestRate($source, $target);

                return $rate === null ? [] : [
                    'rate' => $rate->rate,
                    'observed_at' => $rate->observedAt->toIso8601String(),
                    'provider' => $rate->provider,
                ];
            }
        );

        return $cached === [] ? null : new MarketRate(
            source: $source,
            target: $target,
            rate: (float) $cached['rate'],
            observedAt: Carbon::parse($cached['observed_at']),
            provider: (string) $cached['provider'],
        );
    }

    private function configuredProvider(): MarketRateProviderInterface
    {
        $class = AllowedClass::resolve(config('scribe.market_rate_provider'), MarketRateProviderInterface::class)
            ?? throw new ValidationException('No market rate provider is configured (scribe.market_rate_provider).');

        return new $class();
    }
}
