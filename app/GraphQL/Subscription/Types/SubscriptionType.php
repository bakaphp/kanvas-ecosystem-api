<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Types;

use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;

class SubscriptionType
{
    public function provider(Subscription $subscription): ?string
    {
        /** @var AppsStripeCustomer|null $customer */
        $customer = AppsStripeCustomer::find($subscription->apps_stripe_customer_id);

        return $customer?->provider;
    }
}
