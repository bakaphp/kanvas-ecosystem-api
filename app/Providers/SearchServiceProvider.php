<?php

declare(strict_types=1);

namespace App\Providers;

use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Override;

class SearchServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->singleton(SearchEngineResolver::class);
    }

    #[Override]
    public function boot()
    {
        // Make sure both engines are configured in config/scout.php
    }
}
