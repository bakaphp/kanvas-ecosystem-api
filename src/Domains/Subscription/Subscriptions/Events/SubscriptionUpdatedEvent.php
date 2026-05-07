<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Events;

use App\GraphQL\Ecosystem\Types\UserType;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Users\Models\Users;
use Override;

class SubscriptionUpdatedEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected AppsStripeCustomer $appsStripeCustomer,
        protected Users $users
    ) {
    }

    public function broadcastWith(): array
    {
        $userCurrentSubscription = (new UserType())->currentSubscription($this->users, []);
        return [
            'apps_stripe_customer_id' => $this->appsStripeCustomer->id,
            'users_id' => $this->users->id,
            'user_stripe_subscription_status' => $userCurrentSubscription->stripe_status ?? null,
            'user_stripe_subscription_type' => $userCurrentSubscription->type ?? null,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('subscription-updated-channel-on-' . $this->appsStripeCustomer->apps_id . '-for-' . $this->users->id);
    }

    public function broadcastAs(): string
    {
        return 'subscription.updated';
    }
}
