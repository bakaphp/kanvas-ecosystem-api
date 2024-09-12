<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
use Kanvas\Subscription\SubscriptionItems\Actions\UpdateSubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\Actions\CreateSubscriptionItem;

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
            'name' => $this->subscriptionDto->name ?? $this->subscription->name,
            'payment_method_id' => $this->subscriptionDto->payment_method_id ?? $this->subscription->payment_method_id,
            'trial_days' => $this->subscriptionDto->trial_days ?? $this->subscription->trial_days,
        ]);

        return $this->subscription;
    }
}