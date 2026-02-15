<?php

declare(strict_types=1);

namespace App\Providers;

use App\Macros\ScoutMacros;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;
use Override;
use Silber\Bouncer\BouncerFacade as Bouncer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    #[Override]
    public function register()
    {
        //Sanctum::ignoreMigrations();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Sanctum::usePersonalAccessTokenModel(Sessions::class);
        Cashier::useCustomerModel(AppsStripeCustomer::class);
        Bouncer::cache(); // Enable caching for Bouncer to use redis
        ScoutMacros::register();

        RateLimiter::for('graphql', function (Request $request) {
            $userId = $request->user()?->id;

            return Limit::perMinute(config('kanvas.ratelimit.max_attempts'))->by($userId !== null ? $userId : $request->ip());
        });
    }
}
