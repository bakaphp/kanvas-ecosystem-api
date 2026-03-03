<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Observers;

use Kanvas\Subscription\Subscriptions\Events\SubscriptionUpdatedEvent;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;

class AppsStripeCustomerObserver
{
    public function updated(AppsStripeCustomer $customer): void
    {
        SubscriptionUpdatedEvent::dispatch($customer, $customer->user);
    }
}
