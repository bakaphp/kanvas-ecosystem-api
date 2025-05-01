<?php

namespace Kanvas\Souk\Cart\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Enums\ConfigurationEnum;

class CartEventsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! Schema::hasTable('apps_settings')) {
            return;
        }

        $app = app(Apps::class);
        $this->registerCartEvents($app);
    }

    private function registerCartEvents(Apps $app): void
    {
        $events = [
            ConfigurationEnum::EVENT_LARAVEL_CART_ADDED->value,
            ConfigurationEnum::EVENT_LARAVEL_CART_UPDATED->value,
        ];

        foreach ($events as $event) {
            $handler = $app->get($event);

            if ($handler) {
                Event::listen($event, function ($item) use ($app, $handler) {
                    $cart = app('cart')->session($item['session_key']);
                    (new $handler($app, $cart, $item))->execute();
                });
            }
        }
    }
}
