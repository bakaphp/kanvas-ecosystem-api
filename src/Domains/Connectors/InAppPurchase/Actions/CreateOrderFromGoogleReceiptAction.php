<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Currencies\Models\Currencies;
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
use Imdhemy\Purchases\Facades\Product;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Kanvas\Connectors\InAppPurchase\Enums\GooglePlayReceiptStatusEnum;
use Imdhemy\GooglePlay\ClientFactory;

class CreateOrderFromGoogleReceiptAction
{
    private const DEFAULT_CURRENCY = 'USD';
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

        print_r(config('kanvas.app.google.google_play_credentials_json'));
        die();
        $receipt = [
            'productId' => $this->googlePlayInAppPurchase->product_id,
            'orderId' => $this->googlePlayInAppPurchase->order_id,
            'purchaseToken' => $this->googlePlayInAppPurchase->purchase_token,
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
        $client = ClientFactory::createWithJsonKey(json_decode(file_get_contents(config('kanvas.app.google.google_play_credentials_json')), true));
        return Product::googlePlay($client)->id($receipt['productId'])->token($receipt['purchaseToken'])->get();
    }

    private function createPeople(): People
    {
        return (new CreatePeopleFromUserAction(
            $this->app,
            $this->company->defaultBranch,
            $this->user
        ))->execute();
    }

    private function createOrderData(array $allReceiptData, $people): Order
    {
        $orderItem = $this->createOrderItem($allReceiptData);

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
            total: $this->calculateTotal($orderItem),
            taxes: 0.0,
            totalDiscount: 0.0,
            totalShipping: 0.0,
            status: 'completed',
            orderNumber: '',
            shippingMethod: null,
            currency: $this->region->currency,
            fulfillmentStatus: 'fulfilled',
            items: OrderItem::collect([$orderItem], DataCollection::class),
            metadata: $allReceiptData,
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );
    }

    private function createOrderItem(array $inAppData): OrderItem
    {
        $variant = $this->getVariant($inAppData['productId']);
        $warehouse = $this->region->warehouses()->firstOrFail();

        return new OrderItem(
            app: $this->app,
            variant: $variant,
            name: $variant->name,
            sku: $inAppData['productId'],
            quantity: 1,
            price: $variant->getPrice($warehouse),
            tax: 0.0,
            discount: 0.0,
            currency: Currencies::getByCode(self::DEFAULT_CURRENCY),
            quantityShipped: 0
        );
    }

    private function getVariant(string $sku): Variants
    {
        return Variants::getBySku($sku, $this->company, $this->app);
    }

    private function calculateTotal(OrderItem $orderItem): float
    {
        return $orderItem->quantity * $orderItem->price;
    }
}
