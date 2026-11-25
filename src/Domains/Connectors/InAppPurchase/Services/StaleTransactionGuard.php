<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Carbon\Carbon;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Apple delivers a lineage's transactions to the purchase mutation and to the webhook, late and out of
 * order. Both ask this before writing, so a replay can neither roll back a renewal nor reopen a refund.
 */
final class StaleTransactionGuard
{
    /**
     * A refund ends access at the refund date, before the refunded period's expiry, so once that access has
     * ended a transaction is measured by purchase date: anything bought on or before it is covered by it.
     * A missing purchase date counts as stale, so unknown data never reopens access.
     */
    public static function isStale(CashierSubscription $stored, ?Carbon $purchasedAt, ?Carbon $expiresAt): bool
    {
        if ($stored->ends_at === null) {
            return false;
        }

        if ($stored->stripe_status === SubscriptionStatusEnum::CANCELED->value && $stored->ended()) {
            return $purchasedAt === null || $purchasedAt->lessThanOrEqualTo($stored->ends_at);
        }

        return $expiresAt !== null && $expiresAt->lessThan($stored->ends_at);
    }
}
