<?php

namespace App\Providers;

use Bouncer;
use Illuminate\Support\ServiceProvider;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Sessions\Models\Sessions;
use Laravel\Sanctum\Sanctum;
use Laravel\Cashier\Cashier;
use Kanvas\Companies\Models\Companies;

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
        Cashier::useCustomerModel(Companies::class);
    }
}
