<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Jobs;

use Imdhemy\GooglePlay\DeveloperNotifications\DeveloperNotification;
use Imdhemy\GooglePlay\DeveloperNotifications\SubscriptionNotification;
use Kanvas\Connectors\InAppPurchase\Enums\GoogleNotificationTypeEnum;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

class ProcessGoogleSubscriptionWebhookJob extends ProcessWebhookJob
{
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

        $newStatus = $typeEnum->toSubscriptionStatus();

        /** @var AppsStripeCustomer|null $appsStripeCustomer */
        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $this->receiver->app->getId())
            ->where('provider', 'google_play')
            ->where('stripe_id', $purchaseToken)
            ->first();

        if (! $appsStripeCustomer) {
            return [
                'message' => 'No matching Google Play subscription found',
                'purchase_token' => $purchaseToken,
                'notification_type' => $notificationType,
                'subscription_id' => $subscriptionId,
                'package_name' => $packageName,
                'type_enum' => $typeEnum->name,
            ];
        }

        /** @var CashierSubscription|null $subscription */
        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', $purchaseToken)
            ->first();

        if ($subscription) {
            $subscription->stripe_status = $newStatus;
            $subscription->saveOrFail();
        }

        return [
            'message' => 'Google Play subscription webhook processed',
            'notification_type' => $notificationType,
            'notification_name' => $typeEnum->name,
            'subscription_id' => $subscriptionId,
            'package_name' => $packageName,
            'new_status' => $newStatus,
        ];
    }
}
