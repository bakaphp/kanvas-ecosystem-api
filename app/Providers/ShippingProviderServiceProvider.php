<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Connectors\Dhl\Providers\DhlShippingProvider;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Override;

class ShippingProviderServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->instance(ShippingProviderFactory::API_PROVIDER_NAMES, [DhlShippingProvider::NAME]);

        $this->app->bind(
            'shipping_provider.' . DhlShippingProvider::NAME,
            fn ($app, array $params): DhlShippingProvider => new DhlShippingProvider($params['app'], $params['company'])
        );
    }
}
