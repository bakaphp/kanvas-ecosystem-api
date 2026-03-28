<?php

declare(strict_types=1);

namespace App\Providers;

use Kanvas\Souk\Cart\Services\CustomCart;
use Kanvas\Souk\Cart\Support\RedisStorage;
use Override;
use Wearepixel\Cart\CartServiceProvider as CartCartServiceProvider;

class CartServiceProvider extends CartCartServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->singleton('cart', function ($app) {
            $config = config('cart');
            $events = $app['events'];

            $storage = new RedisStorage('cart', $config);

            return new CustomCart(
                $storage,
                $events,
                'cart',
                'cart',
                $config
            );
        });
    }
}
