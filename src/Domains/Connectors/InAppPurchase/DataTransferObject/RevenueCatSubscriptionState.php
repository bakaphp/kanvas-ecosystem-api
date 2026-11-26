<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\DataTransferObject;

use Carbon\Carbon;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatAutoRenewalStatusEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatEventTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatPeriodTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatReasonEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatSubscriptionStatusEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;

/**
 * One RevenueCat subscription lineage as Kanvas stores it, read either from REST API v2 or, without
 * API access, from a webhook event.
 */
final readonly class RevenueCatSubscriptionState
{
    public function __construct(
        public string $productId,
        public Carbon $expiresAt,
        public ?Carbon $refundedAt = null,
        public ?Carbon $billingIssueAt = null,
        public ?Carbon $gracePeriodExpiresAt = null,
        public bool $isUnsubscribed = false,
        public bool $isPaused = false,
        public bool $isTrial = false,
        public string $environment = '',
        public string $store = '',
    ) {
    }

    /**
     * v2 has no refund date: a subscription that no longer gives access (refund, revocation, billing
     * retry, pause) has ended by now at the latest, and one still giving access past its period end is
     * in grace until $accessExtendedTo (its customer's latest entitlement expiry).
     *
     * @param array<string, mixed> $subscription an item of GET /v2/projects/{id}/customers/{id}/subscriptions
     */
    public static function fromV2Subscription(
        string $productId,
        array $subscription,
        ?Carbon $accessExtendedTo = null
    ): ?self {
        $status = RevenueCatSubscriptionStatusEnum::tryFrom((string) ($subscription['status'] ?? ''));
        $autoRenewal = RevenueCatAutoRenewalStatusEnum::tryFrom((string) ($subscription['auto_renewal_status'] ?? ''));
        $givesAccess = ! empty($subscription['gives_access']);
        $isPaused = $status === RevenueCatSubscriptionStatusEnum::PAUSED
            || $autoRenewal === RevenueCatAutoRenewalStatusEnum::WILL_PAUSE;
        $periodEnd = self::msDate($subscription['ends_at'] ?? $subscription['current_period_ends_at'] ?? null)
            ?? ($isPaused ? now() : null);

        if ($periodEnd === null) {
            return null;
        }

        $expiresAt = $givesAccess
            ? ($accessExtendedTo?->greaterThan($periodEnd) ? $accessExtendedTo : $periodEnd)
            : $periodEnd->min(now());
        $hasBillingIssue = $status === RevenueCatSubscriptionStatusEnum::IN_GRACE_PERIOD
            || $status === RevenueCatSubscriptionStatusEnum::IN_BILLING_RETRY;

        return new self(
            productId: $productId,
            expiresAt: $expiresAt,
            billingIssueAt: $hasBillingIssue ? now() : null,
            isUnsubscribed: $autoRenewal?->endsAtPeriodEnd() ?? false,
            isPaused: $isPaused,
            isTrial: $status === RevenueCatSubscriptionStatusEnum::TRIALING,
            environment: strtoupper((string) ($subscription['environment'] ?? '')),
            store: strtoupper((string) ($subscription['store'] ?? '')),
        );
    }

    /**
     * An event only describes the lineage at the moment it was sent, so each one is read on its own:
     * a RENEWAL after a CANCELLATION is active again, which is what RevenueCat means by it.
     *
     * @param array<string, mixed> $event
     */
    public static function fromEvent(array $event, RevenueCatEventTypeEnum $type): ?self
    {
        $expiresAt = self::msDate($event['expiration_at_ms'] ?? null);

        if ($expiresAt === null) {
            return null;
        }

        $eventAt = self::msDate($event['event_timestamp_ms'] ?? null) ?? now();
        $reason = RevenueCatReasonEnum::tryFrom((string) ($event['cancel_reason'] ?? $event['expiration_reason'] ?? ''));
        $isCancellation = $type === RevenueCatEventTypeEnum::CANCELLATION;
        $isRefund = $isCancellation && $reason === RevenueCatReasonEnum::CUSTOMER_SUPPORT;
        $hasBillingIssue = $type === RevenueCatEventTypeEnum::BILLING_ISSUE || $reason === RevenueCatReasonEnum::BILLING_ERROR;

        return new self(
            productId: (string) ($event['product_id'] ?? ''),
            expiresAt: $expiresAt,
            refundedAt: $isRefund ? $expiresAt->min($eventAt) : null,
            billingIssueAt: $hasBillingIssue ? $eventAt : null,
            gracePeriodExpiresAt: self::msDate($event['grace_period_expiration_at_ms'] ?? null),
            isUnsubscribed: $isCancellation,
            isPaused: $type === RevenueCatEventTypeEnum::SUBSCRIPTION_PAUSED || $reason === RevenueCatReasonEnum::SUBSCRIPTION_PAUSED,
            isTrial: RevenueCatPeriodTypeEnum::fromValue($event['period_type'] ?? null) === RevenueCatPeriodTypeEnum::TRIAL,
            environment: strtoupper((string) ($event['environment'] ?? '')),
            store: strtoupper((string) ($event['store'] ?? '')),
        );
    }

    /**
     * A refund ends access at the refund; during a billing issue the store keeps retrying until the
     * grace period lapses, and access runs until then.
     */
    public function endsAt(): Carbon
    {
        if ($this->refundedAt !== null) {
            return $this->refundedAt;
        }

        if ($this->billingIssueAt !== null && $this->gracePeriodExpiresAt?->greaterThan($this->expiresAt)) {
            return $this->gracePeriodExpiresAt;
        }

        return $this->expiresAt;
    }

    public function trialEndsAt(): ?Carbon
    {
        return $this->isTrial && $this->refundedAt === null ? $this->expiresAt : null;
    }

    /**
     * past_due is the only status Cashier treats as inactive, so it is kept for a billing issue whose
     * grace period has lapsed; inside the grace period the subscription stays active, as on Google.
     */
    public function status(): SubscriptionStatusEnum
    {
        if ($this->refundedAt !== null) {
            return SubscriptionStatusEnum::EXPIRED;
        }

        if ($this->endsAt()->isPast()) {
            return match (true) {
                $this->billingIssueAt !== null => SubscriptionStatusEnum::PAST_DUE,
                $this->isPaused => SubscriptionStatusEnum::PAUSED,
                default => SubscriptionStatusEnum::EXPIRED,
            };
        }

        return match (true) {
            $this->billingIssueAt !== null => SubscriptionStatusEnum::ACTIVE,
            $this->isPaused => SubscriptionStatusEnum::PAUSED,
            $this->isUnsubscribed => SubscriptionStatusEnum::CANCELED,
            default => SubscriptionStatusEnum::ACTIVE,
        };
    }

    private static function msDate(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampMs((int) $value) : null;
    }
}
