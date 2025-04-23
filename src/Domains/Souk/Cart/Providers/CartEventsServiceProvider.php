<?php

namespace Kanvas\Souk\Cart\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Enums\ConfigurationEnum;

class CartEventsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (Schema::hasTable('apps_settings')) {
            $app = app(Apps::class);
            $added = $app->get(ConfigurationEnum::EVENT_LARAVEL_CART_ADDED->value);
            $updated = $app->get(ConfigurationEnum::EVENT_LARAVEL_CART_UPDATED->value);
            Event::listen(ConfigurationEnum::EVENT_LARAVEL_CART_ADDED->value, function ($item) use ($app, $added) {
                if ($added) {
                    (new $added($app, $item))->execute();
                }
            });
            Event::listen(ConfigurationEnum::EVENT_LARAVEL_CART_UPDATED->value, function ($item) use ($app, $updated) {
                if ($updated) {
                    (new $updated($app, $item))->execute();
                }
            });
        }
    }
}
