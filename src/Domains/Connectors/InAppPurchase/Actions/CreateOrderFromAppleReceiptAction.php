<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Carbon\Carbon;
use Imdhemy\AppStore\ClientFactory;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Imdhemy\AppStore\Receipts\Verifier;
use Imdhemy\AppStore\ValueObjects\LatestReceiptInfo;
use Imdhemy\AppStore\ValueObjects\Receipt;
use Imdhemy\Purchases\Facades\Subscription;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

class CreateOrderFromAppleReceiptAction extends CreateOrderFromReceiptActionBase
{
    private const string DEFAULT_CURRENCY = 'USD';

    public function __construct(
        protected readonly AppleInAppPurchaseReceipt $appleInAppPurchase,
        protected bool $runInSandbox = false
    ) {
        $this->app = $appleInAppPurchase->app;
        $this->company = $appleInAppPurchase->company;
        $this->user = $appleInAppPurchase->user;
        $this->region = $appleInAppPurchase->region;
    }

    /**
     * @throws ValidationException
     */
    #[Override]
    public function execute(): ModelsOrder
    {
        if (! $this->appleInAppPurchase->is_renewable_subscription) {
            return $this->executeProductFlow();
        }

        return $this->executeRenewableSubscriptionFlow();
    }

    #[Override]
    protected function getCustomFields(): array
    {
        return $this->appleInAppPurchase->custom_fields;
    }

    #[Override]
    protected function verifyReceipt(array $receipt): ReceiptResponse
    {
        $sharedSecret = $this->app->get(ConfigurationEnum::APPLE_PAYMENT_SHARED_SECRET->value);

        if (empty($sharedSecret)) {
            throw new ValidationException('No Apple Payment Shared Secret Configured');
        }

        $client = $this->runInSandbox ? ClientFactory::createSandbox() : ClientFactory::create();
        $verifier = new Verifier($client, $receipt['transactionReceipt'], $sharedSecret);

        return $verifier->verify(true, $this->runInSandbox ? $client : null);
    }

    protected function verifyRenewableReceipt(array $receipt): ReceiptResponse
    {
        $sharedSecret = $this->app->get(ConfigurationEnum::APPLE_PAYMENT_SHARED_SECRET->value);

        if (empty($sharedSecret)) {
            throw new ValidationException('No Apple Payment Shared Secret Configured');
        }

        $client = $this->runInSandbox ? ClientFactory::createSandbox() : ClientFactory::create();

        return Subscription::appStore($client)
            ->receiptData($receipt['transactionReceipt'])
            ->password($sharedSecret)
            ->verifyRenewable($this->runInSandbox ? $client : null);
    }

    private function executeProductFlow(): ModelsOrder
    {
        $receipt = $this->buildReceiptPayload();
        $verifiedReceipt = $this->verifyReceipt($receipt);
        $receiptStatus = $verifiedReceipt->getStatus();

        if (! $receiptStatus->isValid()) {
            throw new ValidationException('Invalid Receipt');
        }

        $people = $this->createPeople();
        $orderData = $this->createOrderData(
            $receipt,
            $people,
            $verifiedReceipt->getReceipt()
        );

        $order = new CreateOrderAction($orderData)->execute();
        $this->handleCustomFieldsOnOrder($order);
        $this->tagOrderAsIap($order);

        return $order;
    }

    private function executeRenewableSubscriptionFlow(): ModelsOrder
    {
        $receipt = $this->buildReceiptPayload();
        $verifiedReceipt = $this->verifyRenewableReceipt($receipt);
        $receiptStatus = $verifiedReceipt->getStatus();

        if (! $receiptStatus->isValid()) {
            throw new ValidationException('Invalid Renewable Subscription Receipt');
        }

        $resolvedReceiptInfo = $this->resolveRenewableReceiptInfo(
            $verifiedReceipt,
            $receipt['transactionId'],
            $this->appleInAppPurchase->original_transaction_id,
            $this->appleInAppPurchase->product_id
        );

        if ($resolvedReceiptInfo === null) {
            throw new ValidationException('No matching renewable subscription transaction found in receipt');
        }

        $existingOrder = $this->findExistingAppleSubscriptionOrder($resolvedReceiptInfo->getTransactionId());
        if ($existingOrder instanceof ModelsOrder) {
            $this->tagOrderAsIapSubscription($existingOrder);

            return $existingOrder;
        }

        $people = $this->createPeople();
        $orderData = $this->createRenewableOrderData(
            $receipt,
            $people,
            $verifiedReceipt,
            $resolvedReceiptInfo
        );
        $order = new CreateOrderAction($orderData)->execute();

        $this->handleCustomFieldsOnOrder($order);
        $this->upsertAppleSubscription($resolvedReceiptInfo, $verifiedReceipt, $order);
        $this->tagOrderAsIapSubscription($order);

        return $order;
    }

    private function buildReceiptPayload(): array
    {
        return [
            'productId' => $this->appleInAppPurchase->product_id,
            'transactionId' => $this->appleInAppPurchase->transaction_id,
            'transactionReceipt' => $this->appleInAppPurchase->receipt,
            'transactionDate' => $this->appleInAppPurchase->transaction_date,
            'custom_fields' => $this->appleInAppPurchase->custom_fields,
        ];
    }

    private function createOrderData(
        array $allReceiptData,
        People $people,
        ?Receipt $receipt,
    ): Order {
        if ($receipt === null) {
            $exception = new ValidationException('Receipt validation failed: null receipt received');
            report($exception);

            throw $exception;
        }

        // Get in-app purchases with detailed validation
        $inAppPurchases = $receipt->getInApp();

        if (empty($inAppPurchases)) {
            $exception = new ValidationException(
                'No in-app purchases found in receipt. This appears to be an app download receipt only.'
            );
            report($exception);

            throw $exception;
        }

        // Find the purchase that matches the transaction_id from the request
        $requestedTransactionId = $allReceiptData['transactionId'];
        $matchingPurchase = null;

        foreach ($inAppPurchases as $purchase) {
            if ($purchase->getTransactionId() === $requestedTransactionId) {
                $matchingPurchase = $purchase;

                break;
            }
        }

        if ($matchingPurchase === null) {
            $exception = new ValidationException(
                "Transaction {$requestedTransactionId} not found in receipt. The receipt may be stale or the transaction was not completed."
            );
            report($exception);

            throw $exception;
        }

        $firstVariant = $this->getVariant($matchingPurchase->getProductId());
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItem = $this->createOrderItem(
            $firstVariant,
            $matchingPurchase->getQuantity()
        );

        $orderItems[] = $orderItem;

        $this->processCustomFieldsVariants($orderItems);
        $allReceiptData['source'] = 'apple';
        $this->addMessageMetadataFromCustomFields($allReceiptData);

        if (array_key_exists('custom_fields', $allReceiptData)) {
            foreach ($allReceiptData['custom_fields'] as $key => $value) {
                if ($key == 'message_id') {
                    $message = Message::fromApp($this->app)
                        ->where('id', $value)
                        ->first();

                    if (! $message) {
                        continue;
                    }
                    $messageContent = $message->message;
                    $allReceiptData['message']['users_id'] = $message->user->getId();
                    $allReceiptData['message']['creator_display_name'] = $message->user->displayname ?? null;
                    $allReceiptData['message']['creator_email'] = $message->user->email;
                    $allReceiptData['message']['id'] = $message->getId();
                    $allReceiptData['message']['user_subscription_tier'] = $message->getId();
                    $allReceiptData['message']['prompt_title'] = $messageContent['title'] ?? $message->slug;
                }
            }
        }

        return $this->createOrderDto(
            $orderItems,
            $people,
            $allReceiptData
        );
    }

    private function createRenewableOrderData(
        array $allReceiptData,
        People $people,
        ReceiptResponse $verifiedReceipt,
        LatestReceiptInfo $receiptInfo
    ): Order {
        $variant = $this->getVariant($receiptInfo->getProductId());
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItems[] = $this->createOrderItem(
            $variant,
            $receiptInfo->getQuantity()
        );

        $this->processCustomFieldsVariants($orderItems);
        $allReceiptData['source'] = 'apple';
        $allReceiptData['apple_subscription'] = $this->normalizeAppleSubscriptionMetadata($verifiedReceipt, $receiptInfo);
        $this->addMessageMetadataFromCustomFields($allReceiptData);

        return $this->createOrderDto(
            $orderItems,
            $people,
            $allReceiptData
        );
    }

    private function resolveRenewableReceiptInfo(
        ReceiptResponse $receiptResponse,
        string $requestedTransactionId,
        ?string $requestedOriginalTransactionId,
        string $requestedProductId
    ): ?LatestReceiptInfo {
        /** @var array<LatestReceiptInfo>|null $latestReceiptInfo */
        $latestReceiptInfo = $receiptResponse->getLatestReceiptInfo();
        if ($latestReceiptInfo === null || $latestReceiptInfo === []) {
            return null;
        }

        foreach ($latestReceiptInfo as $receiptInfo) {
            if ($receiptInfo->getTransactionId() === $requestedTransactionId) {
                return $receiptInfo;
            }
        }

        if ($requestedOriginalTransactionId !== null && $requestedOriginalTransactionId !== '') {
            $matchedByOriginal = array_values(array_filter(
                $latestReceiptInfo,
                fn (LatestReceiptInfo $receiptInfo): bool => $receiptInfo->getOriginalTransactionId() === $requestedOriginalTransactionId
            ));

            if ($matchedByOriginal !== []) {
                return $this->getLatestByPurchaseDate($matchedByOriginal);
            }
        }

        $matchedByProduct = array_values(array_filter(
            $latestReceiptInfo,
            fn (LatestReceiptInfo $receiptInfo): bool => $receiptInfo->getProductId() === $requestedProductId
        ));

        if ($matchedByProduct === []) {
            return null;
        }

        return $this->getLatestByPurchaseDate($matchedByProduct);
    }

    /**
     * @param array<LatestReceiptInfo> $receiptInfos
     */
    private function getLatestByPurchaseDate(array $receiptInfos): LatestReceiptInfo
    {
        usort($receiptInfos, function (LatestReceiptInfo $a, LatestReceiptInfo $b): int {
            $aPurchaseDate = $a->getPurchaseDate()?->toCarbon()?->getTimestamp() ?? 0;
            $bPurchaseDate = $b->getPurchaseDate()?->toCarbon()?->getTimestamp() ?? 0;

            return $bPurchaseDate <=> $aPurchaseDate;
        });

        return $receiptInfos[0];
    }

    private function normalizeAppleSubscriptionMetadata(
        ReceiptResponse $verifiedReceipt,
        LatestReceiptInfo $receiptInfo
    ): array {
        return [
            'transaction_id' => $receiptInfo->getTransactionId(),
            'original_transaction_id' => $receiptInfo->getOriginalTransactionId(),
            'product_id' => $receiptInfo->getProductId(),
            'purchase_date' => $receiptInfo->getPurchaseDate()?->toCarbon()?->toDateTimeString(),
            'expires_date' => $receiptInfo->getExpiresDate()?->toCarbon()?->toDateTimeString(),
            'is_trial_period' => $receiptInfo->getIsTrialPeriod(),
            'is_in_intro_offer_period' => $receiptInfo->getIsInIntroOfferPeriod(),
            'environment' => $verifiedReceipt->getEnvironment(),
        ];
    }

    private function upsertAppleSubscription(
        LatestReceiptInfo $receiptInfo,
        ReceiptResponse $verifiedReceipt,
        ModelsOrder $order
    ): void {
        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => 0,
                'users_id' => $this->user->getId(),
                'provider' => 'apple',
            ],
            [
                'stripe_id' => $receiptInfo->getOriginalTransactionId(),
                'config' => [
                    'environment' => $verifiedReceipt->getEnvironment(),
                    'product_id' => $receiptInfo->getProductId(),
                    'order_id' => $order->getId(),
                ],
            ]
        );

        $subscriptionType = 'apple:' . $receiptInfo->getProductId();
        $expiresDate = $receiptInfo->getExpiresDate()?->toCarbon();
        $trialEndsAt = $receiptInfo->getIsTrialPeriod() ? $expiresDate : null;

        $subscription = $this->findCashierSubscription(
            $appsStripeCustomer->getId(),
            $receiptInfo->getOriginalTransactionId()
        ) ?? new CashierSubscription();

        $subscription->apps_stripe_customer_id = $appsStripeCustomer->getId();
        $subscription->type = $subscriptionType;
        $subscription->stripe_id = $receiptInfo->getOriginalTransactionId();
        $subscription->stripe_price = $receiptInfo->getProductId();
        $subscription->stripe_status = $this->resolveAppleSubscriptionStatus($receiptInfo);
        $subscription->quantity = $receiptInfo->getQuantity();
        $subscription->ends_at = $expiresDate;
        $subscription->trial_ends_at = $trialEndsAt;
        $subscription->saveOrFail();
    }

    private function resolveAppleSubscriptionStatus(LatestReceiptInfo $receiptInfo): string
    {
        if ($receiptInfo->getCancellationDate() !== null) {
            return 'canceled';
        }

        $expiresDate = $receiptInfo->getExpiresDate()?->toCarbon();
        if ($expiresDate instanceof Carbon && $expiresDate->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
