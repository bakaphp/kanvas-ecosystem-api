<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\NervousSystem\Capability\Services\ActiveIntegrationsService;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\RateCardShippingProvider;

class ShippingProviderFactory
{
    public const API_PROVIDER_NAMES = 'shipping.api_providers';

    public static function make(string $name, AppInterface $app, CompanyInterface $company): ShippingRateProviderInterface
    {
        $binding = "shipping_provider.{$name}";

        if (! app()->bound($binding)) {
            throw new InvalidArgumentException("No shipping provider registered for '{$name}'.");
        }

        return app($binding, ['app' => $app, 'company' => $company]);
    }

    public static function forCompany(AppInterface $app, CompanyInterface $company): array
    {
        $activeIntegrationNames = new ActiveIntegrationsService($company)->names();

        $names = app()->bound(self::API_PROVIDER_NAMES) ? app(self::API_PROVIDER_NAMES) : [];

        $providers = [];

        foreach ($names as $name) {
            $provider = self::make($name, $app, $company);
            $integration = $provider->integration();

            if ($integration !== null && in_array($integration->value, $activeIntegrationNames, true)) {
                $providers[] = $provider;
            }
        }

        foreach (self::rateCardProviderKeys($app, $company) as $providerKey) {
            $providers[] = new RateCardShippingProvider($providerKey, $app, $company);
        }

        return $providers;
    }

    private static function rateCardProviderKeys(AppInterface $app, CompanyInterface $company): array
    {
        return RateCard::query()
            ->ownedBy($app, $company)
            ->notDeleted()
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }
}
