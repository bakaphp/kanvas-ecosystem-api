<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Jobs;

use Imdhemy\GooglePlay\DeveloperNotifications\DeveloperNotification;
use Imdhemy\GooglePlay\DeveloperNotifications\SubscriptionNotification;
use Imdhemy\GooglePlay\Subscriptions\SubscriptionPurchase;
use Kanvas\Connectors\InAppPurchase\Concerns\FindsStoreSubscription;
use Kanvas\Connectors\InAppPurchase\Enums\GoogleNotificationTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Services\GooglePlayBillingService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

/**
 * Google Play RTDN. When the app has a Play payment service account the notification is only a signal:
 * status and ends_at come from the Play Developer API, so an unauthenticated push cannot grant or revoke
 * access, whatever type it claims. Play fills cancellation fields when a user only turns off auto-renew,
 * so a REVOKED claim is believed only through the expiry Play reports. If that call fails the job throws
 * and leaves the row alone. Only apps without that account trust the notification itself.
 */
#[WorkflowAction]
class ProcessGoogleSubscriptionWebhookJob extends ProcessWebhookJob
{
    use FindsStoreSubscription;

    #[Override]
    public function execute(): array
    {
        /** @var array $payload */
        $payload = $this->webhookRequest->payload;
        $messageData = $payload['message']['data'] ?? null;

        if (! is_string($messageData) || $messageData === '') {
            return ['message' => 'Missing message.data', 'status' => 'skipped'];
        }

        $developerNotification = DeveloperNotification::parse($messageData);

        if ($developerNotification->isTestNotification()) {
            return ['message' => 'Test notification received', 'notification_type' => 'test'];
        }

        $notificationPayload = $developerNotification->getPayload();

        if (! $notificationPayload instanceof SubscriptionNotification) {
            return [
                'message' => 'Non-subscription notification, skipping',
                'type' => $notificationPayload->getType(),
            ];
        }

        $purchaseToken = $notificationPayload->getPurchaseToken();
        $subscriptionId = $notificationPayload->getSubscriptionId();
        $notificationType = $notificationPayload->getNotificationType();
        $packageName = $developerNotification->getPackageName();

        $typeEnum = GoogleNotificationTypeEnum::tryFrom($notificationType);
        if ($typeEnum === null) {
            return [
                'message' => 'Unknown Google notification type',
                'notification_type' => $notificationType,
            ];
        }

        $subscription = $this->findAppStoreSubscription($purchaseToken, $this->receiver->app, StoreProviderEnum::GOOGLE_PLAY);

        if (! $subscription instanceof CashierSubscription) {
            return [
                'message' => 'No matching Google Play subscription found',
                'purchase_token' => $purchaseToken,
                'notification_type' => $notificationType,
                'subscription_id' => $subscriptionId,
                'package_name' => $packageName,
                'type_enum' => $typeEnum->name,
            ];
        }

        $purchase = $this->fetchSubscriptionPurchase($subscriptionId, $purchaseToken);
        if ($purchase instanceof SubscriptionPurchase) {
            GooglePlayBillingService::applyPurchaseState($subscription, $purchase);
        } else {
            $this->applyNotificationState($subscription, $typeEnum);
        }
        $subscription->saveOrFail();

        return [
            'message' => 'Google Play subscription webhook processed',
            'notification_type' => $notificationType,
            'notification_name' => $typeEnum->name,
            'subscription_id' => $subscriptionId,
            'package_name' => $packageName,
            'new_status' => $subscription->stripe_status,
            'ends_at' => $subscription->ends_at?->toDateTimeString(),
            'source' => $purchase instanceof SubscriptionPurchase ? 'play_api' : 'notification',
        ];
    }

    /**
     * Without Play API access, RTDN carries no expiry, so only a revocation can move ends_at.
     */
    private function applyNotificationState(CashierSubscription $subscription, GoogleNotificationTypeEnum $type): void
    {
        $subscription->stripe_status = $type->toSubscriptionStatus()->value;

        if ($type === GoogleNotificationTypeEnum::SUBSCRIPTION_REVOKED) {
            $subscription->ends_at = now();
        }
    }

    /**
     * A Play API failure is not caught: ProcessWebhookJob reports it and marks the call failed, so it
     * can be replayed (kanvas:retry-webhook-call), and a sync receiver answers 500 so Pub/Sub redelivers.
     */
    private function fetchSubscriptionPurchase(
        string $subscriptionId,
        string $purchaseToken
    ): ?SubscriptionPurchase {
        /** @var GooglePlayBillingService $billing */
        $billing = app(GooglePlayBillingService::class, ['app' => $this->receiver->app]);

        if (! $billing->hasPaymentServiceAccount()) {
            return null;
        }

        return $billing->getSubscription($subscriptionId, $purchaseToken);
    }
}
