<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Carbon\Carbon;
use Closure;
use GuzzleHttp\ClientInterface;
use Imdhemy\AppStore\ClientFactory;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Imdhemy\AppStore\Receipts\Verifier;
use Imdhemy\AppStore\ValueObjects\LatestReceiptInfo;
use Imdhemy\AppStore\ValueObjects\Receipt;
use Imdhemy\Purchases\Facades\Subscription;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;
use Kanvas\Connectors\InAppPurchase\Services\AppleSignedTransactionVerifier;
use Kanvas\Connectors\InAppPurchase\Services\StaleTransactionGuard;
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
    public const string CLIENT_BINDING = 'in-app-purchase.apple.client';
    private const string DEFAULT_CURRENCY = 'USD';
    private const int OFFER_TYPE_INTRODUCTORY = 1;
    private const string OFFER_DISCOUNT_FREE_TRIAL = 'FREE_TRIAL';
    private const string TYPE_AUTO_RENEWABLE_SUBSCRIPTION = 'Auto-Renewable Subscription';

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
        $hasSignedTransaction = ! empty($this->appleInAppPurchase->signed_transaction);

        if ($this->appleInAppPurchase->is_renewable_subscription && $hasSignedTransaction) {
            return $this->executeSignedTransactionFlow();
        }

        if ($this->appleInAppPurchase->receipt === '') {
            throw new ValidationException(
                'An Apple receipt is required unless a signed transaction is sent for a renewable subscription'
            );
        }

        if (! $this->appleInAppPurchase->is_renewable_subscription) {
            return $this->executeProductFlow();
        }

        return $this->executeRenewableSubscriptionFlow();
    }

    /**
     * StoreKit 2: the transaction is verified from its own signature, so no app receipt is needed. That
     * receipt is missing on development installs and never exists under Xcode StoreKit testing.
     */
    private function executeSignedTransactionFlow(): ModelsOrder
    {
        $transaction = new AppleSignedTransactionVerifier($this->app)
            ->verify((string) $this->appleInAppPurchase->signed_transaction);

        $productId = (string) ($transaction['productId'] ?? '');
        if ($productId !== $this->resolveStoreProductId($this->appleInAppPurchase->product_id)) {
            throw new ValidationException('Signed transaction is for a different product');
        }

        if (! empty($transaction['revocationDate'])) {
            throw new ValidationException('Transaction was cancelled or revoked by Apple.');
        }

        // A consumable or non-renewing purchase has no expiry and would be stored as a never-ending subscription.
        if (($transaction['type'] ?? null) !== self::TYPE_AUTO_RENEWABLE_SUBSCRIPTION) {
            throw new ValidationException('Signed transaction is not an auto-renewable subscription');
        }

        if (! is_numeric($transaction['expiresDate'] ?? null)) {
            throw new ValidationException('Signed subscription transaction has no expiration date');
        }

        $metadata = $this->normalizeSignedTransactionMetadata($transaction);

        return $this->recordAppleSubscription(
            $metadata,
            fn (): ModelsOrder => $this->createSignedTransactionOrder($metadata)
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createSignedTransactionOrder(array $metadata): ModelsOrder
    {
        $orderItems = [
            $this->createOrderItem($this->getVariant($metadata['product_id']), $metadata['quantity']),
        ];
        $this->processCustomFieldsVariants($orderItems);

        $orderMetadata = $this->buildReceiptPayload();
        $orderMetadata['transactionId'] = $metadata['transaction_id'];
        $orderMetadata['transactionReceipt'] = null;
        $orderMetadata['source'] = StoreProviderEnum::APPLE->value;
        $orderMetadata['apple_subscription'] = $metadata;
        $this->addMessageMetadataFromCustomFields($orderMetadata);

        return new CreateOrderAction(
            $this->createOrderDto($orderItems, $this->createPeople(), $orderMetadata)
        )->execute();
    }

    /**
     * One transaction lineage (original transaction id) is one subscription. A transaction already
     * recorded, or a stale one (StaleTransactionGuard), creates no order and leaves the stored
     * subscription as is: a replayed old JWS would otherwise roll back a renewal or revive a refund.
     *
     * @param array<string, mixed> $metadata normalizeSignedTransactionMetadata()
     *                                     or normalizeAppleSubscriptionMetadata()
     * @param Closure(): ModelsOrder $createOrder
     * @throws ValidationException
     */
    private function recordAppleSubscription(array $metadata, Closure $createOrder): ModelsOrder
    {
        return $this->withStorePurchaseLock(
            $metadata['original_transaction_id'],
            fn (): ModelsOrder => $this->findOrCreateAppleSubscriptionOrder($metadata, $createOrder)
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @param Closure(): ModelsOrder $createOrder
     * @throws ValidationException
     */
    private function findOrCreateAppleSubscriptionOrder(array $metadata, Closure $createOrder): ModelsOrder
    {
        $subscription = $this->findOwnedCashierSubscription($metadata['original_transaction_id']);

        $order = $this->findExistingOrderByAppleTransactionId($metadata['transaction_id']);
        if ($order instanceof ModelsOrder) {
            return $this->reuseExistingSubscriptionOrder(
                $order,
                $subscription,
                fn () => $this->upsertAppleSubscription($metadata, $order, null)
            );
        }

        $isStale = $subscription instanceof CashierSubscription && StaleTransactionGuard::isStale(
            $subscription,
            $this->toCarbon($metadata['purchase_date']),
            $this->toCarbon($metadata['expires_date'])
        );

        if ($isStale) {
            $originalTransactionId = $metadata['original_transaction_id'];
            $order = $this->findExistingOrderByAppleOriginalTransactionId($originalTransactionId)
                ?? throw new ValidationException('Transaction is older than the current subscription');
            $this->tagOrderAsIapSubscription($order);

            return $order;
        }

        $order = $createOrder();
        $this->handleCustomFieldsOnOrder($order);
        $this->upsertAppleSubscription($metadata, $order, $subscription);
        $this->tagOrderAsIapSubscription($order);

        return $order;
    }

    private function toCarbon(?string $dateTime): ?Carbon
    {
        return $dateTime !== null ? Carbon::parse($dateTime) : null;
    }

    /**
     * offerType 1 is any introductory offer; only a FREE_TRIAL discount is a trial.
     *
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    private function normalizeSignedTransactionMetadata(array $transaction): array
    {
        $date = static fn (mixed $ms): ?string => is_numeric($ms)
            ? Carbon::createFromTimestampMs((int) $ms)->toDateTimeString()
            : null;
        $transactionId = (string) $transaction['transactionId'];
        $offerType = (int) ($transaction['offerType'] ?? 0);
        $offerDiscountType = $transaction['offerDiscountType'] ?? null;

        return [
            'transaction_id' => $transactionId,
            'original_transaction_id' => (string) ($transaction['originalTransactionId'] ?? $transactionId),
            'product_id' => (string) $transaction['productId'],
            'purchase_date' => $date($transaction['purchaseDate'] ?? null),
            'expires_date' => $date($transaction['expiresDate'] ?? null),
            'cancellation_date' => $date($transaction['revocationDate'] ?? null),
            'is_trial_period' => $offerDiscountType === self::OFFER_DISCOUNT_FREE_TRIAL,
            'is_in_intro_offer_period' => $offerType === self::OFFER_TYPE_INTRODUCTORY,
            'environment' => (string) ($transaction['environment'] ?? ''),
            'quantity' => (int) ($transaction['quantity'] ?? 1),
        ];
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

        $client = $this->appleClient();
        $verifier = new Verifier($client, $receipt['transactionReceipt'], $sharedSecret);

        return $verifier->verify(true, $this->runInSandbox ? $client : null);
    }

    protected function verifyRenewableReceipt(array $receipt): ReceiptResponse
    {
        $sharedSecret = $this->app->get(ConfigurationEnum::APPLE_PAYMENT_SHARED_SECRET->value);

        if (empty($sharedSecret)) {
            throw new ValidationException('No Apple Payment Shared Secret Configured');
        }

        $client = $this->appleClient();

        return Subscription::appStore($client)
            ->receiptData($receipt['transactionReceipt'])
            ->password($sharedSecret)
            ->verifyRenewable($this->runInSandbox ? $client : null);
    }

    /**
     * Never bound in production. Tests bind a mocked client here because the order flow is reached
     * through the GraphQL mutation, and Apple's sandbox answers the same receipt with 0 or 211xx at random.
     */
    private function appleClient(): ClientInterface
    {
        if (app()->bound(self::CLIENT_BINDING)) {
            return app(self::CLIENT_BINDING);
        }

        return $this->runInSandbox ? ClientFactory::createSandbox() : ClientFactory::create();
    }

    private function executeProductFlow(): ModelsOrder
    {
        $receipt = $this->buildReceiptPayload();
        $verifiedReceipt = $this->verifyReceipt($receipt);
        $receiptStatus = $verifiedReceipt->getStatus();

        if (! $receiptStatus->isValid()) {
            throw new ValidationException('Invalid Receipt');
        }

        $this->assertReceiptBelongsToApp($verifiedReceipt);

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

        $this->assertReceiptBelongsToApp($verifiedReceipt);

        $resolvedReceiptInfo = $this->resolveRenewableReceiptInfo(
            $verifiedReceipt,
            $receipt['transactionId'],
            $this->appleInAppPurchase->original_transaction_id,
            $this->appleInAppPurchase->product_id
        );

        if ($resolvedReceiptInfo === null) {
            throw new ValidationException('No matching renewable subscription transaction found in receipt');
        }

        $metadata = $this->normalizeAppleSubscriptionMetadata($verifiedReceipt, $resolvedReceiptInfo);

        if ($metadata['expires_date'] === null) {
            throw new ValidationException('Receipt transaction has no expiry, so it is not a renewable subscription');
        }

        return $this->recordAppleSubscription(
            $metadata,
            fn (): ModelsOrder => new CreateOrderAction(
                $this->createRenewableOrderData(
                    $receipt,
                    $this->createPeople(),
                    $resolvedReceiptInfo,
                    $metadata
                )
            )->execute()
        );
    }

    /**
     * verifyReceipt answers valid for any genuine receipt, and a primary shared secret covers every app
     * on the developer account, so validity alone does not say the purchase was made in this app.
     */
    private function assertReceiptBelongsToApp(ReceiptResponse $verifiedReceipt): void
    {
        new AppleSignedTransactionVerifier($this->app)
            ->assertReceiptBundle($verifiedReceipt->getReceipt()?->getBundleId());
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
                if ($purchase->getCancellationDate() !== null) {
                    throw new ValidationException('Transaction was cancelled or revoked by Apple.');
                }

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
        $allReceiptData['source'] = StoreProviderEnum::APPLE->value;
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

    /**
     * @param array<string, mixed> $subscriptionMetadata
     */
    private function createRenewableOrderData(
        array $allReceiptData,
        People $people,
        LatestReceiptInfo $receiptInfo,
        array $subscriptionMetadata
    ): Order {
        $variant = $this->getVariant($receiptInfo->getProductId());
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItems[] = $this->createOrderItem(
            $variant,
            $receiptInfo->getQuantity()
        );

        $this->processCustomFieldsVariants($orderItems);
        $allReceiptData['source'] = StoreProviderEnum::APPLE->value;
        $allReceiptData['apple_subscription'] = $subscriptionMetadata;
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
        $storeProductId = $this->resolveStoreProductId($requestedProductId);

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
                fn (LatestReceiptInfo $receiptInfo): bool
                    => $receiptInfo->getOriginalTransactionId() === $requestedOriginalTransactionId
            ));

            if ($matchedByOriginal !== []) {
                return $this->getLatestByPurchaseDate($matchedByOriginal);
            }
        }

        $matchedByProduct = array_values(array_filter(
            $latestReceiptInfo,
            fn (LatestReceiptInfo $receiptInfo): bool => $receiptInfo->getProductId() === $storeProductId
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

    /**
     * @return array<string, mixed>
     */
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
            'cancellation_date' => $receiptInfo->getCancellationDate()?->toCarbon()?->toDateTimeString(),
            'is_trial_period' => (bool) $receiptInfo->getIsTrialPeriod(),
            'is_in_intro_offer_period' => (bool) $receiptInfo->getIsInIntroOfferPeriod(),
            'environment' => $verifiedReceipt->getEnvironment(),
            'quantity' => $receiptInfo->getQuantity(),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function upsertAppleSubscription(
        array $metadata,
        ModelsOrder $order,
        ?CashierSubscription $subscription
    ): void {
        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => 0,
                'users_id' => $this->user->getId(),
                'provider' => StoreProviderEnum::APPLE->value,
            ],
            [
                'stripe_id' => $metadata['original_transaction_id'],
                'config' => [
                    'environment' => $metadata['environment'],
                    'product_id' => $metadata['product_id'],
                    'order_id' => $order->getId(),
                ],
            ]
        );

        $expiresDate = $this->toCarbon($metadata['expires_date']);
        $cancellationDate = $this->toCarbon($metadata['cancellation_date']);

        $subscription ??= new CashierSubscription();
        $subscription->apps_stripe_customer_id = $appsStripeCustomer->getId();
        $subscription->type = StoreProviderEnum::APPLE->value . ':' . $metadata['product_id'];
        $subscription->stripe_id = $metadata['original_transaction_id'];
        $subscription->stripe_price = $metadata['product_id'];
        $subscription->stripe_status = $this->resolveAppleSubscriptionStatus($cancellationDate, $expiresDate)->value;
        $subscription->quantity = $metadata['quantity'];
        // A refund ends access at the refund date; the future expiry would keep Cashier's active() true.
        $subscription->ends_at = $cancellationDate ?? $expiresDate;
        $subscription->trial_ends_at = $metadata['is_trial_period'] && $cancellationDate === null
            ? $expiresDate
            : null;
        $subscription->saveOrFail();
    }

    private function resolveAppleSubscriptionStatus(?Carbon $cancellationDate, ?Carbon $expiresDate): SubscriptionStatusEnum
    {
        return match (true) {
            $cancellationDate instanceof Carbon => SubscriptionStatusEnum::CANCELED,
            $expiresDate instanceof Carbon && $expiresDate->isPast() => SubscriptionStatusEnum::EXPIRED,
            default => SubscriptionStatusEnum::ACTIVE,
        };
    }
}
