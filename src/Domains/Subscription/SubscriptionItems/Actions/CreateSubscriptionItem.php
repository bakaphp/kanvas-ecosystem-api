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
        return SubscriptionItem::create([
            'subscription_id' => $this->subscriptionItemDto->subscription_id,
            'stripe_id' => $this->subscriptionItemDto->stripe_id,
            'price_id' => $this->subscriptionItemDto->price_id,
            'stripe_plan' => $this->subscriptionItemDto->stripe_plan,
            'quantity' => $this->subscriptionItemDto->quantity,
        ]);
    }
}
