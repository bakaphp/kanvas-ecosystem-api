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
            'plan_id' => $this->subscriptionItemDto->plan_id,
            'quantity' => $this->subscriptionItemDto->quantity,
        ]);
    }
}