<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Imdhemy\GooglePlay\ClientFactory;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Imdhemy\Purchases\Facades\Product;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\GooglePlayReceiptStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
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

        $order = (new CreateOrderAction($orderData))->execute();

        $this->handleCustomFieldsOnOrder($order);

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
        $googlePaymentConfig = $this->app->get(ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value) ?? $this->app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $googlePackageName = $this->app->get(EnumsConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value);
        if (empty($googlePaymentConfig) && empty($googlePackageName)) {
            throw new ValidationException('Google client config is missing');
        }

        $client = ClientFactory::createWithJsonKey($googlePaymentConfig);

        return Product::googlePlay($client)->packageName($googlePackageName)->id($receipt['productId'])->token($receipt['purchaseToken'])->get();
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
        $allReceiptData['source'] = 'google_play';

        return $this->createOrderDto($orderItems, $people, $allReceiptData);
    }
}
