<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;

class AddSubscriptionItem
{
    public function __construct(
        protected SubscriptionItemDto $subscriptionItemDto
    ) {
    }

    public function execute(): SubscriptionItem
    {
        $subscriptionItem = SubscriptionItem::FirstOrcreate([
            'apps_id' => $this->subscriptionItemDto->app->getId(),
            'companies_id' => $this->subscriptionItemDto->company->getId(),
            'subscription_id' => $this->subscriptionItemDto->subscription_id,
            'stripe_id' => $this->subscriptionItemDto->stripe_id,
            'apps_plans_prices_id' => $this->subscriptionItemDto->apps_plans_prices_id,
            'quantity' => $this->subscriptionItemDto->quantity,
            'apps_plans_id' => $this->subscriptionItemDto->apps_plans_id,
        ]);

        return $subscriptionItem;
    }
}
