<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;

class UpdateSubscription
{
    public function __construct(
        protected Subscription $subscription,
        protected SubscriptionDto $subscriptionDto
    ) {
    }

    public function execute(): Subscription
    {
        $this->subscription->update([
            'stripe_plan' => $this->subscriptionDto->stripe_plan,
            'name' => $this->subscriptionDto->name,
            'stripe_id' => $this->subscriptionDto->stripe_id,
            'is_active' => $this->subscriptionDto->is_active,
            'is_cancelled' => $this->subscriptionDto->is_cancelled,
            'paid' => $this->subscriptionDto->paid,
            'charge_date' => $this->subscriptionDto->charge_date,
        ]);

        return $this->subscription;
    }
}