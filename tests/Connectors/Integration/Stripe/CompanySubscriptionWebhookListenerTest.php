<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Events\CompanySubscriptionUpdatedEvent;
use Kanvas\Subscription\Subscriptions\Listeners\CompanySubscriptionWebhookListener;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

final class CompanySubscriptionWebhookListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Stripe integration tests are skipped in CI');
        }
    }

    public function testDispatchesCompanyEventForCompanyScopedCustomer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $stripeCustomerId = 'cus_test_' . Str::random(10);
        $customer = AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'stripe_id' => $stripeCustomerId,
        ]);
        Subscription::create([
            'apps_stripe_customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'past_due',
            'stripe_price' => 'price_test_listener',
            'quantity' => 1,
        ]);

        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        new CompanySubscriptionWebhookListener()->handle(new WebhookHandled([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => $stripeCustomerId]],
        ]));

        Event::assertDispatched(CompanySubscriptionUpdatedEvent::class);
    }

    public function testSkipsUserScopedCustomer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $stripeCustomerId = 'cus_test_' . Str::random(10);
        $customer = AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => 0,
            'users_id' => $user->getId(),
            'stripe_id' => $stripeCustomerId,
        ]);
        Subscription::create([
            'apps_stripe_customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_user_listener',
            'quantity' => 1,
        ]);

        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        new CompanySubscriptionWebhookListener()->handle(new WebhookHandled([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => $stripeCustomerId]],
        ]));

        Event::assertNotDispatched(CompanySubscriptionUpdatedEvent::class);
    }

    public function testIgnoresUnrelatedEventTypes(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $stripeCustomerId = 'cus_test_' . Str::random(10);
        AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'stripe_id' => $stripeCustomerId,
        ]);

        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        new CompanySubscriptionWebhookListener()->handle(new WebhookHandled([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['customer' => $stripeCustomerId]],
        ]));

        Event::assertNotDispatched(CompanySubscriptionUpdatedEvent::class);
    }

    public function testIgnoresUnknownCustomer(): void
    {
        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        new CompanySubscriptionWebhookListener()->handle(new WebhookHandled([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_unknown_' . Str::random(8)]],
        ]));

        Event::assertNotDispatched(CompanySubscriptionUpdatedEvent::class);
    }

    public function testSkipsCustomerFromDifferentApp(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $stripeCustomerId = 'cus_test_' . Str::random(10);
        $otherAppId = app(Apps::class)->getId() + 9999;
        $customer = AppsStripeCustomer::create([
            'apps_id' => $otherAppId,
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'stripe_id' => $stripeCustomerId,
        ]);
        Subscription::create([
            'apps_stripe_customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_other_app',
            'quantity' => 1,
        ]);

        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        new CompanySubscriptionWebhookListener()->handle(new WebhookHandled([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => $stripeCustomerId]],
        ]));

        Event::assertNotDispatched(CompanySubscriptionUpdatedEvent::class);
    }
}
