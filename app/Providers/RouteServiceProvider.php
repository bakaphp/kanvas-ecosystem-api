<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\IndexController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Override;

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
    #[Override]
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
            $user = $request->user();
            $userId = $user !== null ? $user->id : null;

            return Limit::perMinutes(
                config('kanvas.ratelimit.decay_minutes'),
                config('kanvas.ratelimit.max_attempts')
            )->by($userId !== null ? $userId : $request->ip());
        });
    }

    /**
     * Register Routes function.
     */
    protected function registerRoutes(): void
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(base_path('routes/api.php'));
        });

        Route::get('/', [IndexController::class, 'index']);
    }

    /**
     * Routes Configuration.
     */
    protected function routeConfiguration(): array
    {
        return
        [
            'prefix' => config('kanvas.application.routes.prefix'),
            'middleware' => config('kanvas.application.routes.middleware'),
        ];
    }
}
