<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Actions;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;

class UpdateSubscriptionItem
{
    public function __construct(
        protected SubscriptionItem $subscriptionItem,
        protected SubscriptionItemDto $subscriptionItemDto
    ) {
    }

    public function execute(): SubscriptionItem
    {
        $this->subscriptionItem->update([
            'quantity' => $this->subscriptionItemDto->quantity,
        ]);

        return $this->subscriptionItem;
    }
}
