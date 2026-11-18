<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Imdhemy\GooglePlay\Products\ProductPurchase;
use Imdhemy\GooglePlay\Subscriptions\SubscriptionPurchase;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\GooglePlayReceiptStatusEnum;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Services\GooglePlayBillingService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

class CreateOrderFromGoogleReceiptAction extends CreateOrderFromReceiptActionBase
{
    public function __construct(
        protected readonly GooglePlayInAppPurchaseReceipt $googlePlayInAppPurchase
    ) {
        $this->app = $googlePlayInAppPurchase->app;
        $this->company = $googlePlayInAppPurchase->company;
        $this->user = $googlePlayInAppPurchase->user;
        $this->region = $googlePlayInAppPurchase->region;
    }

    /**
     * @throws ValidationException
     */
    #[Override]
    public function execute(): ModelsOrder
    {
        if (! $this->googlePlayInAppPurchase->is_renewable_subscription) {
            return $this->executeProductFlow();
        }

        return $this->executeRenewableSubscriptionFlow();
    }

    private function executeProductFlow(): ModelsOrder
    {
        $receipt = [
            'productId' => $this->googlePlayInAppPurchase->product_id,
            'orderId' => $this->googlePlayInAppPurchase->order_id,
            'purchaseToken' => $this->googlePlayInAppPurchase->purchase_token,
            'custom_fields' => $this->googlePlayInAppPurchase->custom_fields,
        ];

        $verifiedReceipt = $this->verifyReceipt($receipt);

        // 0 = Purchased, 1 = Canceled, 2 = Pending
        if ($verifiedReceipt->getPurchaseState() == GooglePlayReceiptStatusEnum::CANCELED->value && ! app()->runningUnitTests()) {
            throw new ValidationException('Receipt is in canceled state');
        }

        if ($verifiedReceipt->getPurchaseState() == GooglePlayReceiptStatusEnum::PENDING->value) {
            throw new ValidationException('Receipt is in pending state');
        }

        $people = $this->createPeople();
        $orderData = $this->createOrderData(
            $receipt,
            $people
        );

        $order = new CreateOrderAction($orderData)->execute();

        $this->handleCustomFieldsOnOrder($order);
        $this->tagOrderAsIap($order);

        return $order;
    }

    private function executeRenewableSubscriptionFlow(): ModelsOrder
    {
        $resolvedSku = $this->resolveStoreProductId($this->googlePlayInAppPurchase->product_id);

        $receipt = [
            'productId' => $resolvedSku,
            'orderId' => $this->googlePlayInAppPurchase->order_id,
            'purchaseToken' => $this->googlePlayInAppPurchase->purchase_token,
            'custom_fields' => $this->googlePlayInAppPurchase->custom_fields,
        ];

        $verifiedReceipt = $this->verifyRenewableReceipt($receipt);

        if ($verifiedReceipt->getPaymentState() === SubscriptionPurchase::PAYMENT_STATE_PENDING && ! app()->runningUnitTests()) {
            throw new ValidationException('Subscription receipt is in pending state');
        }

        // Validated before any order exists; upsertGoogleSubscription would otherwise fail after creating one.
        GooglePlayBillingService::expiryOf($verifiedReceipt);

        $resolvedOrderId = $verifiedReceipt->getOrderId() ?? $receipt['orderId'];
        if (empty($resolvedOrderId)) {
            throw new ValidationException('Google subscription order id is missing');
        }

        return $this->withStorePurchaseLock(
            $receipt['purchaseToken'],
            fn (): ModelsOrder => $this->findOrCreateGoogleSubscriptionOrder($receipt, $verifiedReceipt, $resolvedOrderId)
        );
    }

    /**
     * @throws ValidationException
     */
    private function findOrCreateGoogleSubscriptionOrder(
        array $receipt,
        SubscriptionPurchase $verifiedReceipt,
        string $resolvedOrderId
    ): ModelsOrder {
        $subscription = $this->findOwnedCashierSubscription($receipt['purchaseToken']);

        $existingOrder = $this->findExistingOrderByGoogleSubscriptionOrderId($resolvedOrderId);
        if ($existingOrder instanceof ModelsOrder) {
            return $this->reuseExistingSubscriptionOrder(
                $existingOrder,
                $subscription,
                fn () => $this->upsertGoogleSubscription(
                    $receipt,
                    $verifiedReceipt,
                    $existingOrder,
                    null
                )
            );
        }

        $people = $this->createPeople();
        $orderData = $this->createRenewableOrderData($receipt, $people, $verifiedReceipt);
        $order = new CreateOrderAction($orderData)->execute();

        $this->handleCustomFieldsOnOrder($order);
        $this->upsertGoogleSubscription(
            $receipt,
            $verifiedReceipt,
            $order,
            $subscription
        );
        $this->tagOrderAsIapSubscription($order);

        return $order;
    }

    #[Override]
    protected function getCustomFields(): array
    {
        return $this->googlePlayInAppPurchase->custom_fields;
    }

    #[Override]
    protected function verifyReceipt(array $receipt): ProductPurchase
    {
        return $this->billing()->getProduct($receipt['productId'], $receipt['purchaseToken']);
    }

    protected function verifyRenewableReceipt(array $receipt): SubscriptionPurchase
    {
        return $this->billing()->getSubscription($receipt['productId'], $receipt['purchaseToken']);
    }

    private function billing(): GooglePlayBillingService
    {
        return app(GooglePlayBillingService::class, ['app' => $this->app]);
    }

    private function createOrderData(array $allReceiptData, People $people): Order
    {
        $firstVariant = $this->getVariant($allReceiptData['productId']);
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItem = $this->createOrderItem(
            $firstVariant,
            1  // Google Play doesn't have quantity in receipt
        );

        $orderItems[] = $orderItem;

        $this->processCustomFieldsVariants($orderItems);
        $allReceiptData['source'] = StoreProviderEnum::GOOGLE_PLAY->value;

        return $this->createOrderDto($orderItems, $people, $allReceiptData);
    }

    private function createRenewableOrderData(
        array $allReceiptData,
        People $people,
        SubscriptionPurchase $receipt
    ): Order {
        $variant = $this->getVariant($allReceiptData['productId']);
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItems[] = $this->createOrderItem(
            $variant,
            1
        );

        $this->processCustomFieldsVariants($orderItems);
        $allReceiptData['source'] = StoreProviderEnum::GOOGLE_PLAY->value;
        $allReceiptData['google_subscription'] = [
            'order_id' => $receipt->getOrderId() ?? $allReceiptData['orderId'],
            'purchase_token' => $allReceiptData['purchaseToken'],
            'linked_purchase_token' => $receipt->getLinkedPurchaseToken(),
            'product_id' => $allReceiptData['productId'],
            'expiry_time' => $receipt->getExpiryTime()?->carbon?->toDateTimeString(),
            'payment_state' => $receipt->getPaymentState(),
            'acknowledgement_state' => $receipt->getAcknowledgementState(),
            'auto_renewing' => $receipt->isAutoRenewing(),
        ];
        $this->addMessageMetadataFromCustomFields($allReceiptData);

        return $this->createOrderDto($orderItems, $people, $allReceiptData);
    }

    private function upsertGoogleSubscription(
        array $receipt,
        SubscriptionPurchase $subscriptionPurchase,
        ModelsOrder $order,
        ?CashierSubscription $subscription
    ): void {
        $subscriptionExternalId = $receipt['purchaseToken'];

        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => 0,
                'users_id' => $this->user->getId(),
                'provider' => StoreProviderEnum::GOOGLE_PLAY->value,
            ],
            [
                'stripe_id' => $subscriptionExternalId,
                'config' => [
                    'product_id' => $receipt['productId'],
                    'latest_order_id' => $subscriptionPurchase->getOrderId(),
                    'order_id' => $order->getId(),
                ],
            ]
        );

        $subscription ??= new CashierSubscription();
        $subscription->apps_stripe_customer_id = $appsStripeCustomer->getId();
        $subscription->type = StoreProviderEnum::GOOGLE_PLAY->value . ':' . $receipt['productId'];
        $subscription->stripe_id = $subscriptionExternalId;
        $subscription->stripe_price = $receipt['productId'];
        $subscription->quantity = 1;
        GooglePlayBillingService::applyPurchaseState($subscription, $subscriptionPurchase);
        $subscription->saveOrFail();
    }
}
