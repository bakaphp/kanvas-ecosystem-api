<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Actions;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;

class CreateSubscriptionItem
{
    public function __construct(
        protected SubscriptionItemDto $subscriptionItemDto
    ) {
    }

    public function execute(): SubscriptionItem
    {
        return SubscriptionItem::firstOrCreate(
            [
                'subscription_id' => $this->subscriptionItemDto->subscription_id,
                'apps_plans_id' => $this->subscriptionItemDto->apps_plans_id,
                'stripe_id' => $this->subscriptionItemDto->stripe_id,
            ],
            [
                'quantity' => $this->subscriptionItemDto->quantity,
            ]
        );
    }
}
