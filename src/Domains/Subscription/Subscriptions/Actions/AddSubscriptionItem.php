<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;

class AddSubscriptionItem
{
    protected SubscriptionItemDto $subscriptionItemDto;

    public function __construct(SubscriptionItemDto $subscriptionItemDto)
    {
        $this->subscriptionItemDto = $subscriptionItemDto;
    }

    public function execute(): SubscriptionItem
    {
        $subscriptionItem = SubscriptionItem::create([
            'subscription_id' => $this->subscriptionItemDto->subscription_id,
            'stripe_id' => $this->subscriptionItemDto->stripe_id,
            'price_id' => $this->subscriptionItemDto->price_id,
            'quantity' => $this->subscriptionItemDto->quantity,
            'apps_plans_id' => $this->subscriptionItemDto->apps_plans_id,
        ]);

        return $subscriptionItem;
    }
}
