<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Repositories;

use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
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