<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();
        $this->registerRoutes();
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Register Routes function.
     *
     * @return void
     */
    protected function registerRoutes() : void
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(base_path('routes/api.php'));
        });
    }

    /**
     * Routes Configuration.
     *
     * @return array
     */
    protected function routeConfiguration() : array
    {
        return
        [
            'prefix' => config('kanvas.application.routes.prefix'),
            'middleware' => config('kanvas.application.routes.middleware'),
        ];
    }
}
