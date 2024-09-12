<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;

class SubscriptionItemRepository
{
    /**
     * Get the model instance for SubscriptionItem.
     *
     * @return Model
     */
    public static function getModel(): Model
    {
        return new SubscriptionItem();
    }

    /**
     * Get a subscriptionItem by its ID.
     *
     * @param int $id
     * @return SubscriptionItem
     */
    public static function getById(int $id): SubscriptionItem
    {
        return SubscriptionItem::findOrFail($id);
    }

    /**
     * Get a subscriptionItem by its Subscription ID.
     *
     * @param int $subscriptionId
     * @return SubscriptionItem
     */
    public static function getBySubscriptionId(int $subscriptionId): SubscriptionItem
    {
        return SubscriptionItem::where('subscription_id', $subscriptionId)->firstOrFail();
    }
}
