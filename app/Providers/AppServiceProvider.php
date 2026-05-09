<?php

declare(strict_types=1);

namespace App\Providers;

use App\Macros\ScoutMacros;
use Baka\Support\IPInfo;
use Bavix\Wallet\Models\Purchase as WalletPurchase;
use Bavix\Wallet\WalletConfigure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Services\KanvasGeminiGateway;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Providers\GeminiProvider;
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
        WalletConfigure::ignoreMigrations();

        $this->app->singleton(ConversationStore::class, KanvasConversationStore::class);

        $this->app->extend(
            WalletPurchase::class,
            fn (WalletPurchase $purchase): WalletPurchase => $purchase->setConnection(config('wallet.database.connection'))
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Ai::extend('gemini', function ($app, $config) {
            return new GeminiProvider(
                new KanvasGeminiGateway($app['events']),
                $config,
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        });

        Sanctum::usePersonalAccessTokenModel(Sessions::class);
        Cashier::useCustomerModel(AppsStripeCustomer::class);
        Bouncer::cache(); // Enable caching for Bouncer to use redis
        ScoutMacros::register();

        RateLimiter::for('graphql', function (Request $request) {
            $userId = $request->user()?->id;

            return Limit::perMinute(config('kanvas.ratelimit.max_attempts'))->by($userId !== null ? $userId : IPInfo::getClientIp($request));
        });
    }
}
