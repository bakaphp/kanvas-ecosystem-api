<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Override;

class ShippingProviderServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->instance(ShippingProviderFactory::API_PROVIDER_NAMES, []);
    }
}
