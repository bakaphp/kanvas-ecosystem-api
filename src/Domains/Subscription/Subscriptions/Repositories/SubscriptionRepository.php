<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Repositories;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

class SubscriptionRepository
{
    public static function create(array $data): Subscription
    {
        return Subscription::create($data);
    }

    public static function cancel(int $id): Subscription
    {
        $subscription = Subscription::findOrFail($id);
        $subscription->cancel();
        return $subscription;
    }
}