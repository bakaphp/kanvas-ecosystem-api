<?php

declare(strict_types=1);

namespace App\GraphQL\Subscriptions\Mutations\SubscriptionItems;

use Kanvas\Subscription\SubscriptionItems\Repositories\SubscriptionItemRepository;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Illuminate\Support\Facades\Auth;

class SubscriptionItemMutation
{
    /**
     * addSubscriptionItem.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return SubscriptionItem
     */
    public function addSubscriptionItem(mixed $root, array $req): SubscriptionItem
    {
        $subscriptionItem = SubscriptionItemRepository::create($req['subscriptionId'], $req['planId']);
        return $subscriptionItem;
    }

    /**
     * removeSubscriptionItem.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function removeSubscriptionItem(mixed $root, array $req): bool
    {
        return SubscriptionItemRepository::delete($req['id']);
    }
}