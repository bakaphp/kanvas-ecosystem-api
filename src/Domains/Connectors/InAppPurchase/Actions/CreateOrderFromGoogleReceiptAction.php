<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Imdhemy\GooglePlay\ClientFactory;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Imdhemy\Purchases\Facades\Product;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\GooglePlayReceiptStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Spatie\LaravelData\DataCollection;

class CreateOrderFromGoogleReceiptAction
{
    private AppInterface $app;
    private CompanyInterface $company;
    private UserInterface $user;
    private Regions $region;

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
    public function execute(): ModelsOrder
    {
        $receipt = [
            'productId' => $this->googlePlayInAppPurchase->product_id,
            'orderId' => $this->googlePlayInAppPurchase->order_id,
            'purchaseToken' => $this->googlePlayInAppPurchase->purchase_token,
            'custom_fields' => $this->googlePlayInAppPurchase->custom_fields,
        ];

        $verifiedReceipt = $this->verifyReceipt($receipt);

        // 0 = Purchased, 1 = Canceled, 2 = Pending
        if ($verifiedReceipt->getPurchaseState() == GooglePlayReceiptStatusEnum::CANCELED->value) {
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

        $order = (new CreateOrderAction($orderData))->execute();

        if (! empty($this->googlePlayInAppPurchase->custom_fields)) {
            $order->setCustomFields($this->googlePlayInAppPurchase->custom_fields);
            $order->saveCustomFields();
        }

        return $order;
    }

    private function verifyReceipt(array $receipt): ProductPurchase
    {
        $googlePaymentConfig = $this->app->get(ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value) ?? $this->app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $googlePackageName = $this->app->get(EnumsConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value);
        if (empty($googlePaymentConfig) && empty($googlePackageName)) {
            throw new ValidationException('Google client config is missing');
        }

        $client = ClientFactory::createWithJsonKey($googlePaymentConfig);

        return Product::googlePlay($client)->packageName($googlePackageName)->id($receipt['productId'])->token($receipt['purchaseToken'])->get();
    }

    private function createPeople(): People
    {
        return (new CreatePeopleFromUserAction(
            $this->app,
            $this->company->defaultBranch,
            $this->user
        ))->execute();
    }

    private function createOrderData(array $allReceiptData, People $people): Order
    {
        $firstVariant = $this->getVariant($allReceiptData['productId']);
        $orderItems = [];

        $orderItem = $this->createOrderItem(
            $firstVariant,
            1  // Google Play doesn't have quantity in receipt
        );

        $orderItems[] = $orderItem;

        $this->processCustomFieldsVariants($orderItems);

        return new Order(
            app: $this->app,
            region: $this->region,
            company: $this->company,
            people: $people,
            user: $this->user,
            email: $this->user->email,
            phone: $this->user->cell_phone_number,
            token: Str::random(32),
            shippingAddress: null,
            billingAddress: null,
            total: $this->calculateTotal($orderItems),
            taxes: 0.0,
            totalDiscount: 0.0,
            totalShipping: 0.0,
            status: 'completed',
            orderNumber: '',
            shippingMethod: null,
            currency: $this->region->currency,
            fulfillmentStatus: 'fulfilled',
            items: OrderItem::collect($orderItems, DataCollection::class),
            metadata: $allReceiptData,
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );
    }

    private function createOrderItem(Variants $variant, int $quantity): OrderItem
    {
        $warehouse = $this->region->warehouses()->firstOrFail();

        return new OrderItem(
            app: $this->app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->sku,
            quantity: $quantity,
            price: $variant->getPrice($warehouse),
            tax: 0.0,
            discount: 0.0,
            currency: $this->region->currency,
            quantityShipped: 0
        );
    }

    private function getVariant(string $sku): Variants
    {
        return Variants::getBySku($sku, $this->company, $this->app);
    }

    /**
     * @param array<OrderItem> $orderItems
     */
    private function calculateTotal(array $orderItems): float
    {
        return array_reduce($orderItems, fn ($total, $item) =>
            $total + ($item->quantity * $item->price), 0);
    }

    private function processCustomFieldsVariants(array &$orderItems): void
    {
        /**
         * Normalize custom_fields to associative array: ['name' => value]
         * Example input:
         * [
         *   ['name' => 'message_id', 'value' => 1],
         *   ['name' => 'variants_skus', 'value' => [ ... ]]
         * ]
         */
        $customFieldsAssoc = [];
        if (! empty($this->googlePlayInAppPurchase->custom_fields)) {
            foreach ($this->googlePlayInAppPurchase->custom_fields as $field) {
                if (isset($field['name']) && array_key_exists('value', $field)) {
                    $customFieldsAssoc[$field['name']] = $field['value'];
                }
            }
        }

        if (! empty($customFieldsAssoc['variants_skus']) && is_array($customFieldsAssoc['variants_skus'])) {
            foreach ($customFieldsAssoc['variants_skus'] as $lineItemVariant) {
                if (! is_array($lineItemVariant) || ! isset($lineItemVariant['sku'])) {
                    continue;
                }

                $variant = $this->getVariant($lineItemVariant['sku']);
                $orderItem = $this->createOrderItem(
                    $variant,
                    $lineItemVariant['quantity'] ?? 1
                );

                $orderItems[] = $orderItem;
            }
        }
    }
}
