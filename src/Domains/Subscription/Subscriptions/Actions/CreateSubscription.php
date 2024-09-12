<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
use Kanvas\Subscription\SubscriptionItems\Actions\CreateSubscriptionItem;

class CreateSubscription
{
    public function __construct(
        protected SubscriptionDto $subscriptionDto
    ) {
    }

    public function execute(): Subscription
    {
        $subscription = Subscription::firstOrcreate([
            'users_id' => $this->subscriptionDto->user->getId(),
            'companies_id' => $this->subscriptionDto->company->getId(),
            'apps_id' => $this->subscriptionDto->app->getId(),
            'name' => $this->subscriptionDto->name,
            'stripe_id' => $this->subscriptionDto->stripe_id,
            'is_active' => $this->subscriptionDto->is_active,
            'is_cancelled' => $this->subscriptionDto->is_cancelled,
            'paid' => $this->subscriptionDto->paid,
            'charge_date' => $this->subscriptionDto->charge_date,
            'payment_method_id' => $this->subscriptionDto->payment_method_id,
        ]);

        return $subscription;
    }
}
