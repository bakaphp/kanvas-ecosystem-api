<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Throwable;

class GetShippingQuotesAction
{
    public const CACHE_TTL_SECONDS = 600;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected ?ShipmentRequest $request,
    ) {
    }

    public function execute(): array
    {
        $currency = Str::trimToNull($this->region->currency?->code);

        if ($this->request === null || $currency === null) {
            return [];
        }

        $quotes = [];

        foreach (ShippingProviderFactory::forCompany($this->app, $this->company) as $provider) {
            array_push($quotes, ...$this->quoteProvider($provider, $this->request));
        }

        $quotes = array_values(array_filter(
            $quotes,
            fn (ShippingQuote $quote): bool => strcasecmp(trim($quote->currency), $currency) === 0
        ));

        usort($quotes, fn (ShippingQuote $a, ShippingQuote $b): int => $a->amount <=> $b->amount);

        return $quotes;
    }

    private function quoteProvider(ShippingRateProviderInterface $provider, ShipmentRequest $request): array
    {
        try {
            if ($provider->integration() === null) {
                return $provider->quote($request);
            }

            $cached = Cache::remember(
                $this->cacheKey($provider, $request),
                self::CACHE_TTL_SECONDS,
                fn (): array => array_map(
                    fn (ShippingQuote $quote): array => $quote->toArray(),
                    $provider->quote($request)
                )
            );

            return array_map(fn (array $quote): ShippingQuote => ShippingQuote::from($quote), $cached);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function cacheKey(ShippingRateProviderInterface $provider, ShipmentRequest $request): string
    {
        $parcels = $request->parcels->toCollection()->map(
            fn (Parcel $parcel): string => implode('x', [
                $parcel->weight,
                $parcel->lengthCm,
                $parcel->widthCm,
                $parcel->heightCm,
            ])
        )->implode(',');

        return 'shipping-quotes:' . sha1(implode('|', [
            $this->company->getId(),
            $this->app->getId(),
            $provider->name(),
            $request->destinationCountry->code,
            $request->destinationCity,
            $request->destinationPostalCode,
            $request->originCountry?->code,
            $request->originCity,
            $request->originPostalCode,
            $request->totalWeight(),
            $parcels,
            $request->shipDate?->toDateString(),
        ]));
    }
}
