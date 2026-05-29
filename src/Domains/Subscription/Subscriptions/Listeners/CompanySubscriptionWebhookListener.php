<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Events\CompanySubscriptionUpdatedEvent;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

class CompanySubscriptionWebhookListener
{
    /** @var list<string> */
    private const array RELEVANT_EVENT_TYPES = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
    ];

    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::RELEVANT_EVENT_TYPES, true)) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;
        if (! is_array($object)) {
            return;
        }

        $stripeCustomerId = $object['customer'] ?? null;
        if (! is_string($stripeCustomerId) || $stripeCustomerId === '') {
            return;
        }

        $customer = AppsStripeCustomer::query()
            ->where('stripe_id', $stripeCustomerId)
            ->where('apps_id', app(Apps::class)->getId())
            ->first();

        if ($customer === null || $customer->companies_id === 0) {
            // user-scoped customers stay on the existing SubscriptionUpdatedEvent path
            return;
        }

        /** @var Subscription|null $subscription */
        $subscription = $customer->subscriptions()->latest('id')->first();
        if ($subscription === null) {
            return;
        }

        CompanySubscriptionUpdatedEvent::dispatch($subscription, $customer);
    }
}
