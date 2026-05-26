<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Plans\Listeners\CatalogSyncWebhookListener;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Prices\Models\Price;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

final class CatalogSyncWebhookListenerTest extends TestCase
{
    public function testProductDeletedSoftDeletesLocalPlan(): void
    {
        $app = app(Apps::class);
        $stripeId = 'prod_test_' . Str::random(10);

        DB::table('apps_plans')->insert([
            'apps_id' => $app->getId(),
            'name' => 'plan to delete via webhook',
            'stripe_id' => $stripeId,
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planId = Plan::query()->where('stripe_id', $stripeId)->firstOrFail()->id;

        new CatalogSyncWebhookListener()->handle(new WebhookReceived([
            'type' => 'product.deleted',
            'data' => ['object' => ['id' => $stripeId]],
        ]));

        $this->assertDatabaseHas('apps_plans', [
            'id' => $planId,
            'is_deleted' => 1,
        ]);
    }

    public function testProductUpdatedMirrorsFieldsToLocalPlan(): void
    {
        $app = app(Apps::class);
        $stripeId = 'prod_test_' . Str::random(10);

        DB::table('apps_plans')->insert([
            'apps_id' => $app->getId(),
            'name' => 'original name',
            'description' => 'original',
            'stripe_id' => $stripeId,
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        new CatalogSyncWebhookListener()->handle(new WebhookReceived([
            'type' => 'product.updated',
            'data' => ['object' => [
                'id' => $stripeId,
                'name' => 'renamed via webhook',
                'description' => 'updated',
                'active' => false,
            ]],
        ]));

        $this->assertDatabaseHas('apps_plans', [
            'stripe_id' => $stripeId,
            'name' => 'renamed via webhook',
            'description' => 'updated',
            'is_active' => 0,
        ]);
    }

    public function testPriceDeletedSoftDeletesLocalPrice(): void
    {
        $app = app(Apps::class);
        $planStripeId = 'prod_test_' . Str::random(10);
        $priceStripeId = 'price_test_' . Str::random(10);

        DB::table('apps_plans')->insert([
            'apps_id' => $app->getId(),
            'name' => 'plan for price-delete',
            'stripe_id' => $planStripeId,
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planId = Plan::query()->where('stripe_id', $planStripeId)->firstOrFail()->id;

        DB::table('apps_plans_prices')->insert([
            'apps_plans_id' => $planId,
            'stripe_id' => $priceStripeId,
            'amount' => 19.99,
            'currency' => 'USD',
            'interval' => 'month',
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        new CatalogSyncWebhookListener()->handle(new WebhookReceived([
            'type' => 'price.deleted',
            'data' => ['object' => ['id' => $priceStripeId]],
        ]));

        $this->assertDatabaseHas('apps_plans_prices', [
            'stripe_id' => $priceStripeId,
            'is_deleted' => 1,
        ]);
    }

    public function testIgnoresProductFromDifferentApp(): void
    {
        $stripeId = 'prod_test_' . Str::random(10);
        $otherAppId = app(Apps::class)->getId() + 99999;

        DB::table('apps_plans')->insert([
            'apps_id' => $otherAppId,
            'name' => 'foreign plan',
            'stripe_id' => $stripeId,
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        new CatalogSyncWebhookListener()->handle(new WebhookReceived([
            'type' => 'product.deleted',
            'data' => ['object' => ['id' => $stripeId]],
        ]));

        $this->assertDatabaseHas('apps_plans', [
            'stripe_id' => $stripeId,
            'is_deleted' => 0,
        ]);
    }

    public function testIgnoresUnrelatedEventTypes(): void
    {
        $app = app(Apps::class);
        $stripeId = 'prod_test_' . Str::random(10);

        DB::table('apps_plans')->insert([
            'apps_id' => $app->getId(),
            'name' => 'untouched',
            'stripe_id' => $stripeId,
            'is_active' => 1,
            'is_default' => 0,
            'is_deleted' => 0,
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        new CatalogSyncWebhookListener()->handle(new WebhookReceived([
            'type' => 'customer.created',
            'data' => ['object' => ['id' => $stripeId]],
        ]));

        $this->assertDatabaseHas('apps_plans', [
            'stripe_id' => $stripeId,
            'is_deleted' => 0,
        ]);
    }
}
