<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
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
    }
}
