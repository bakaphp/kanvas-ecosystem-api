<?php

declare(strict_types=1);

namespace App\GraphQL\Subscriptions\Mutations\Subscriptions;

use Kanvas\Subscription\Subscriptions\Repositories\SubscriptionRepository;
use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Illuminate\Support\Facades\Auth;

class SubscriptionMutation
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return Subscription
     */
    public function create(mixed $root, array $req): Subscription
    {
        $subscription = SubscriptionRepository::create($req['input']);
        return $subscription;
    }

    /**
     * cancel.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return Subscription
     */
    public function cancel(mixed $root, array $req): Subscription
    {
        $subscription = SubscriptionRepository::cancel($req['id']);
        return $subscription;
    }
}
