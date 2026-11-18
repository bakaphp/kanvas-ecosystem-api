<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Jobs;

use Imdhemy\AppStore\Jws\Parser;
use Imdhemy\AppStore\ServerNotifications\V2DecodedPayload;
use Kanvas\Connectors\InAppPurchase\Concerns\FindsStoreSubscription;
use Kanvas\Connectors\InAppPurchase\Enums\AppleNotificationTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Services\AppleJwsVerifier;
use Kanvas\Connectors\InAppPurchase\Services\AppleSignedTransactionVerifier;
use Kanvas\Connectors\InAppPurchase\Services\StaleTransactionGuard;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

#[WorkflowAction]
class ProcessAppleSubscriptionWebhookJob extends ProcessWebhookJob
{
    use FindsStoreSubscription;

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
        if (! app(AppleJwsVerifier::class)->verify($jws)) {
            return ['message' => 'Invalid Apple signature', 'status' => 'rejected'];
        }

        $decodedPayload = V2DecodedPayload::fromJws($jws);

        // Apple signs every app's notifications with the same chain, so the signature alone does not say it is ours.
        try {
            new AppleSignedTransactionVerifier($this->receiver->app)
                ->assertBundle($decodedPayload->toArray()['data'] ?? []);
        } catch (ValidationException $e) {
            return ['message' => $e->getMessage(), 'status' => 'rejected'];
        }

        $notificationType = $decodedPayload->getType();
        $subtype = $decodedPayload->getSubType();

        if ($notificationType === V2DecodedPayload::TYPE_TEST) {
            return ['message' => 'Test notification received', 'notification_type' => 'TEST'];
        }

        $typeEnum = AppleNotificationTypeEnum::tryFrom($notificationType);
        if ($typeEnum === null) {
            return [
                'message' => 'Unknown Apple notification type',
                'notification_type' => $notificationType,
            ];
        }

        $transactionInfo = $decodedPayload->getTransactionInfo();
        $originalTransactionId = (string) $transactionInfo->getOriginalTransactionId();
        $productId = $transactionInfo->getProductId();
        $expiresDate = $transactionInfo->getExpiresDate()?->toCarbon();

        $subscription = $this->findAppStoreSubscription(
            $originalTransactionId,
            $this->receiver->app,
            StoreProviderEnum::APPLE
        );

        if (! $subscription instanceof CashierSubscription) {
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

        $unchanged = [
            'notification_type' => $notificationType,
            'subtype' => $subtype,
            'original_transaction_id' => $originalTransactionId,
            'transaction_id' => $transactionInfo->getTransactionId(),
            'current_status' => $subscription->stripe_status,
            'current_ends_at' => $subscription->ends_at?->toDateTimeString(),
        ];

        $isStale = StaleTransactionGuard::isStale(
            $subscription,
            $transactionInfo->getPurchaseDate()?->toCarbon(),
            $expiresDate
        );

        if ($isStale) {
            return [
                'message' => 'Apple notification for an earlier period or a refunded period, current access left unchanged',
                'notification_expires_date' => $expiresDate?->toDateTimeString(),
            ] + $unchanged;
        }

        $newStatus = $typeEnum->toSubscriptionStatus($subtype);

        if ($typeEnum->isPreferenceChange() && ($newStatus === null || $subscription->ended())) {
            return ['message' => 'Apple preference change, current access left unchanged'] + $unchanged;
        }

        $endsAt = match (true) {
            $typeEnum->isRevocation() => $transactionInfo->getRevocationDate()?->toCarbon() ?? now(),
            $typeEnum->isPreferenceChange() => null,
            default => $expiresDate,
        };

        if ($newStatus !== null) {
            $subscription->stripe_status = $newStatus->value;
        }
        if ($endsAt !== null) {
            $subscription->ends_at = $endsAt;
        }
        $subscription->saveOrFail();

        return [
            'message' => 'Apple subscription webhook processed',
            'notification_type' => $notificationType,
            'subtype' => $subtype,
            'original_transaction_id' => $originalTransactionId,
            'product_id' => $productId,
            'new_status' => $subscription->stripe_status,
            'ends_at' => $endsAt?->toDateTimeString(),
        ];
    }
}
