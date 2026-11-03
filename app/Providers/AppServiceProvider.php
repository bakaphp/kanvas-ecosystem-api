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
use Kanvas\Approvals\Services\ApproverResolverRegistryService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Social\Messages\Approvals\ChannelMemberApproverResolver;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Cashier\Cashier;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\ClientManager;
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
    public function boot(ClientManager $mcpClients)
    {
        $mcpClients->registerClient('playwright', fn (): Client => Client::web(
            (string) config('services.playwright_mcp.url')
        )->withTimeout((float) config('services.playwright_mcp.timeout')));

        Sanctum::usePersonalAccessTokenModel(Sessions::class);
        Cashier::useCustomerModel(AppsStripeCustomer::class);
        Bouncer::cache(); // Enable caching for Bouncer to use redis
        ScoutMacros::register();

        // Registered here rather than in the approvals registry's own table: the resolver has to know
        // what a Channel is, and nothing in src/Kanvas/ may depend on a Domains/ sibling.
        ApproverResolverRegistryService::register('channel_members', ChannelMemberApproverResolver::class);

        RateLimiter::for('graphql', function (Request $request) {
            $userId = $request->user()?->id;

            return Limit::perMinute(
                config('kanvas.ratelimit.max_attempts')
            )->by($userId !== null ? $userId : IPInfo::getClientIp($request));
        });
    }
}
