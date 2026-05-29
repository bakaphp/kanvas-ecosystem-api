<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;
use Override;

class CompanySubscriptionUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected Subscription $subscription,
        protected AppsStripeCustomer $customer,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'apps_stripe_customer_id' => $this->customer->id,
            'companies_id' => $this->customer->companies_id,
            'stripe_status' => $this->subscription->stripe_status,
            'stripe_price' => $this->subscription->stripe_price,
            'trial_ends_at' => $this->subscription->trial_ends_at?->toIso8601String(),
            'ends_at' => $this->subscription->ends_at?->toIso8601String(),
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(
            'company-' . $this->customer->companies_id
            . '-app-' . $this->customer->apps_id
            . '-subscription'
        );
    }

    public function broadcastAs(): string
    {
        return 'company.subscription.updated';
    }
}
