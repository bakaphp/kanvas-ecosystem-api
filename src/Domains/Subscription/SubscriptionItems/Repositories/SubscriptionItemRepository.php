<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Repositories;

use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Illuminate\Database\Eloquent\Model;

class SubscriptionItemRepository
{
    public static function create(int $subscriptionId, int $planId): SubscriptionItem
    {
        return SubscriptionItem::create([
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
        ]);
    }

    public static function delete(int $id): bool
    {
        $subscriptionItem = SubscriptionItem::findOrFail($id);
        return $subscriptionItem->delete();
    }
}