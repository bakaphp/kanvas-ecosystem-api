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

    public function status(Subscription $subscription): string
    {
        return $subscription->stripe_status;
    }

    public function planName(Subscription $subscription): string
    {
        return $subscription->type;
    }

    public function startedAt(Subscription $subscription): string
    {
        return $subscription->created_at->format('Y-m-d H:i:s');
    }

    public function isActive(Subscription $subscription): bool
    {
        return $subscription->active();
    }
}
