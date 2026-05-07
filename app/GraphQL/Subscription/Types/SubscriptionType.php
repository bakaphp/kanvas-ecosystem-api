<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Types;

use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;

class SubscriptionType
{
    public function id(Subscription $subscription): ?int
    {
        return $this->getCustomer($subscription)?->id;
    }

    public function provider(Subscription $subscription): ?string
    {
        return $this->getCustomer($subscription)?->provider;
    }

    public function userId(Subscription $subscription): ?int
    {
        return $this->getCustomer($subscription)?->users_id;
    }

    public function companyId(Subscription $subscription): ?int
    {
        return $this->getCustomer($subscription)?->companies_id;
    }

    private function getCustomer(Subscription $subscription): ?AppsStripeCustomer
    {
        /** @var AppsStripeCustomer|null $customer */
        $customer = AppsStripeCustomer::find($subscription->apps_stripe_customer_id);

        return $customer;
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

    public function appsStripeCustomerConfig(Subscription $subscription): ?array
    {
        return $this->getCustomer($subscription)?->config;
    }
}
