<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Jobs;

use Imdhemy\AppStore\Jws\Parser;
use Imdhemy\AppStore\ServerNotifications\V2DecodedPayload;
use Kanvas\Connectors\InAppPurchase\Enums\AppleNotificationTypeEnum;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

class ProcessAppleSubscriptionWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        /** @var array $payload */
        $payload = $this->webhookRequest->payload;
        $signedPayload = $payload['signedPayload'] ?? null;

        if (! is_string($signedPayload) || $signedPayload === '') {
            return ['message' => 'Missing signedPayload', 'status' => 'skipped'];
        }

        $jws = Parser::toJws($signedPayload);
        $decodedPayload = V2DecodedPayload::fromJws($jws);
        $notificationType = $decodedPayload->getType();
        $subtype = $decodedPayload->getSubType();

        if ($notificationType === V2DecodedPayload::TYPE_TEST) {
            return ['message' => 'Test notification received', 'notification_type' => 'TEST'];
        }

        $transactionInfo = $decodedPayload->getTransactionInfo();
        $originalTransactionId = $transactionInfo->getOriginalTransactionId();
        $productId = $transactionInfo->getProductId();
        $expiresDate = $transactionInfo->getExpiresDate()?->toCarbon();

        $typeEnum = AppleNotificationTypeEnum::tryFrom($notificationType);
        if ($typeEnum === null) {
            return [
                'message' => 'Unknown Apple notification type',
                'notification_type' => $notificationType,
            ];
        }

        $newStatus = $this->resolveStatus($typeEnum, $subtype);

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $this->receiver->app->getId())
            ->where('provider', 'apple')
            ->where('stripe_id', $originalTransactionId)
            ->first();

        if (! $appsStripeCustomer) {
            return [
                'message' => 'No matching Apple subscription found',
                'original_transaction_id' => $originalTransactionId,
                'notification_type' => $notificationType,
                'transaction_info' => $transactionInfo,
                'product_id' => $productId,
                'expires_date' => $expiresDate,
                'type_enum' => $typeEnum->name,
            ];
        }

        /** @var CashierSubscription|null $subscription */
        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', $originalTransactionId)
            ->first();

        if ($subscription) {
            $subscription->stripe_status = $newStatus;
            if ($expiresDate) {
                $subscription->ends_at = $expiresDate;
            }
            $subscription->saveOrFail();
        }

        return [
            'message' => 'Apple subscription webhook processed',
            'notification_type' => $notificationType,
            'subtype' => $subtype,
            'original_transaction_id' => $originalTransactionId,
            'product_id' => $productId,
            'new_status' => $newStatus,
        ];
    }

    private function resolveStatus(AppleNotificationTypeEnum $type, ?string $subtype): string
    {
        if ($type === AppleNotificationTypeEnum::DID_CHANGE_RENEWAL_STATUS) {
            return $subtype === V2DecodedPayload::SUBTYPE_AUTO_RENEW_DISABLED
                ? 'canceled'
                : 'active';
        }

        return $type->toSubscriptionStatus();
    }
}
