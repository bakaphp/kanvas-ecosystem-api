<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Actions;

use Kanvas\Subscription\Subscriptions\Models\Subscription;

class CancelSubscription
{
    public function __construct(
        protected Subscription $subscription,
    ) {
    }

    public function execute(): Subscription
    {
        $this->subscription->update([
            'is_cancelled' => true,
            'is_active' => false,
        ]);

        return $this->subscription;
    }
}
