<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Bouncer;
use Kanvas\Apps\Models\Apps;

class BouncerProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
        $app = app(Apps::class);
        Bouncer::scope()->to($app->id);
    }
}
