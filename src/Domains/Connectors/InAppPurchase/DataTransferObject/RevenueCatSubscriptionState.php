<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\DataTransferObject;

use Carbon\Carbon;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatEventTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatPeriodTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatReasonEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;

/**
 * One RevenueCat subscription lineage as Kanvas stores it, read either from the REST API's
 * subscriber.subscriptions[product] or, without a secret key, from a webhook event.
 */
final readonly class RevenueCatSubscriptionState
{
    private const string ENVIRONMENT_SANDBOX = 'SANDBOX';
    private const string ENVIRONMENT_PRODUCTION = 'PRODUCTION';

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
     * @param array<string, mixed> $entry subscriber.subscriptions[productId] of GET /v1/subscribers/{id}
     */
    public static function fromSubscriberEntry(string $productId, array $entry): ?self
    {
        $expiresAt = self::isoDate($entry['expires_date'] ?? null);

        if ($expiresAt === null) {
            return null;
        }

        return new self(
            productId: $productId,
            expiresAt: $expiresAt,
            refundedAt: self::isoDate($entry['refunded_at'] ?? null),
            billingIssueAt: self::isoDate($entry['billing_issues_detected_at'] ?? null),
            gracePeriodExpiresAt: self::isoDate($entry['grace_period_expires_date'] ?? null),
            isUnsubscribed: ! empty($entry['unsubscribe_detected_at']),
            isPaused: ! empty($entry['auto_resume_date']),
            isTrial: RevenueCatPeriodTypeEnum::fromValue($entry['period_type'] ?? null) === RevenueCatPeriodTypeEnum::TRIAL,
            environment: ! empty($entry['is_sandbox']) ? self::ENVIRONMENT_SANDBOX : self::ENVIRONMENT_PRODUCTION,
            store: strtoupper((string) ($entry['store'] ?? '')),
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

    private static function isoDate(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private static function msDate(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampMs((int) $value) : null;
    }
}
