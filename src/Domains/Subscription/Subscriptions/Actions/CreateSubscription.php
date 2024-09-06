<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;

class CreateSubscription
{
    public function __construct(
        protected SubscriptionDto $subscriptionDto
    ) {
    }

    public function execute(): Subscription
    {
        return Subscription::create([
            'user_id' => $this->subscriptionDto->user->getId(),
            'company_id' => $this->subscriptionDto->company->getId(),
            'app_id' => $this->subscriptionDto->app->getId(),
            'plan_id' => $this->subscriptionDto->plan_id,
            'name' => $this->subscriptionDto->name,
            'stripe_id' => $this->subscriptionDto->stripe_id,
            'is_active' => $this->subscriptionDto->is_active,
            'is_cancelled' => $this->subscriptionDto->is_cancelled,
            'paid' => $this->subscriptionDto->paid,
            'charge_date' => $this->subscriptionDto->charge_date,
        ]);
    }
}