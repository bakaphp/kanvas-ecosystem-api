<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Concerns;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;

trait FindsStoreSubscription
{
    /**
     * subscriptions.stripe_id is unique across every app, so a store id has at most one row and each caller
     * checks the scope it needs on that row's owner. Scoping the query instead would hide a row in another app
     * that still blocks the insert.
     */
    protected function findStoreSubscription(string $storeSubscriptionId): ?CashierSubscription
    {
        /** @var CashierSubscription|null */
        return CashierSubscription::query()
            ->with('owner')
            ->where('stripe_id', $storeSubscriptionId)
            ->first();
    }

    /**
     * The customer row's stripe_id only holds the newest store id, so older lineages are found by the
     * subscription's own id.
     */
    protected function findAppStoreSubscription(string $storeSubscriptionId, AppInterface $app, StoreProviderEnum $provider): ?CashierSubscription
    {
        $subscription = $this->findStoreSubscription($storeSubscriptionId);
        $owner = $subscription?->owner;

        $isOwnedByApp = $owner instanceof AppsStripeCustomer
            && (int) $owner->apps_id === $app->getId()
            && $owner->provider === $provider->value;

        return $isOwnedByApp ? $subscription : null;
    }
}
