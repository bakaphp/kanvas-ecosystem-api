<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use App\GraphQL\Subscription\Types\SubscriptionType;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

final class SubscriptionTypeTest extends TestCase
{
    public function testIdReturnsSubscriptionPrimaryKeyNotCustomerId(): void
    {
        // Regression: the resolver used to return the AppsStripeCustomer id
        // (subscription.apps_stripe_customer_id), so the GraphQL `id` couldn't be
        // round-tripped back into cancelSubscription/reactivateSubscription.
        $subscription = new Subscription();
        $subscription->id = 8;
        $subscription->apps_stripe_customer_id = 9;

        $this->assertSame(8, new SubscriptionType()->id($subscription));
    }
}
