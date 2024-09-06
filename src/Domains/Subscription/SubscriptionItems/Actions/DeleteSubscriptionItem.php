<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Actions;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;

class DeleteSubscriptionItem
{
    public function __construct(
        protected SubscriptionItem $subscriptionItem
    ) {
    }

    public function execute(): bool
    {
        return $this->subscriptionItem->delete();
    }
}