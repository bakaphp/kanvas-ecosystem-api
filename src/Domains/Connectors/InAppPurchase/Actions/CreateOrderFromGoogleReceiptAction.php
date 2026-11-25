<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Imdhemy\GooglePlay\Domain\Purchase\Entity\SubscriptionPurchase;
use Imdhemy\GooglePlay\Domain\Purchase\Subscription\SubscriptionState;
use Imdhemy\GooglePlay\Products\ProductPurchase;
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

        if ($verifiedReceipt->subscriptionState === SubscriptionState::PENDING) {
            throw new ValidationException('Subscription receipt is in pending state');
        }

        // Validated before any order exists: a token for another product, or a state without expiry, would
        // otherwise fail in upsertGoogleSubscription after creating one.
        GooglePlayBillingService::expiryOf($verifiedReceipt, $resolvedSku);

        $resolvedOrderId = $this->resolveOrderId($receipt, $verifiedReceipt);
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
        return $this->billing()->getSubscription($receipt['purchaseToken']);
    }

    private function resolveOrderId(array $receipt, SubscriptionPurchase $purchase): ?string
    {
        return GooglePlayBillingService::lineItemOf($purchase, $receipt['productId'])->latestSuccessfulOrderId
            ?? $receipt['orderId'];
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
        $lineItem = GooglePlayBillingService::lineItemOf($receipt, $allReceiptData['productId']);
        $allReceiptData['google_subscription'] = [
            'order_id' => $this->resolveOrderId($allReceiptData, $receipt),
            'purchase_token' => $allReceiptData['purchaseToken'],
            'linked_purchase_token' => $receipt->linkedPurchaseToken,
            'product_id' => $allReceiptData['productId'],
            'expiry_time' => $lineItem->expiresAt?->format('Y-m-d H:i:s'),
            'subscription_state' => $receipt->subscriptionState->value,
            'acknowledgement_state' => $receipt->acknowledgementState->value,
            'auto_renewing' => (bool) $lineItem->autoRenewingPlan?->autoRenewEnabled,
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
                    'latest_order_id' => $this->resolveOrderId($receipt, $subscriptionPurchase),
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
        GooglePlayBillingService::applyPurchaseState($subscription, $subscriptionPurchase, $receipt['productId']);
        $subscription->saveOrFail();
    }
}
