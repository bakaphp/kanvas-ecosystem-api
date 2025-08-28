<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Imdhemy\AppStore\ClientFactory;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Imdhemy\AppStore\Receipts\Verifier;
use Imdhemy\AppStore\ValueObjects\LatestReceiptInfo;
use Imdhemy\AppStore\ValueObjects\Receipt;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\PurchaseTypeEnum;
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
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Kanvas\Souk\Wallet\Actions\AddFundsToWalletAction;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Inventory\Products\Models\Products;

class CreateOrderFromAppleReceiptAction
{
    private const DEFAULT_CURRENCY = 'USD';
    private AppInterface $app;
    private CompanyInterface $company;
    private UserInterface $user;
    private Regions $region;

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
    public function execute(): ModelsOrder
    {
        $receipt = [
            'productId' => $this->appleInAppPurchase->product_id,
            'transactionId' => $this->appleInAppPurchase->transaction_id,
            'transactionReceipt' => $this->appleInAppPurchase->receipt,
            'transactionDate' => $this->appleInAppPurchase->transaction_date,
            'custom_fields' => $this->appleInAppPurchase->custom_fields,
        ];

        //Check if we have a purchase type before verifying the receipt
        if (! $receipt['custom_fields']['purchase_type']) {
            throw new Exception('No purchase type provided');
        }

        $verifiedReceipt = $this->verifyReceipt($receipt);
        $receiptStatus = $verifiedReceipt->getStatus();

        if (! $receiptStatus->isValid()) {
            throw new ValidationException('Invalid Receipt');
        }

        $people = $this->createPeople();

        $product = Products::getByName(
            $receipt['productId'],
            $this->app,
            $this->company,
        );

        $orderItems = [];
        foreach ($receipt['variants_skus'] as $variantSku) {
            $variant = Variants::getBySku(
                $variantSku,
                $this->company,
                $this->app
            );

            $orderItems[] = $this->createOrderItem($variant);
        }

        $orderData = $this->createOrderData(
            $receipt,
            $people,
            $orderItems
        );

        $order = (new CreateOrderAction($orderData))->execute();

        if (! empty($this->appleInAppPurchase->custom_fields)) {
            $order->setCustomFields($this->appleInAppPurchase->custom_fields);
            $order->saveCustomFields();
        }

        // We get the product sku and the sku of the variant(ai_model) from the custom fields
        // We also need to know if this is a consume or a purchase
        match ($receipt['custom_fields']['purchase_type']) {
            WalletConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value => (new AddFundsToWalletAction($order))->execute(),
            WalletConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_CONSUME->value => (new PayFromWalletAction($order))->execute(),
            default => throw new ValidationException('Invalid purchase type'),
        };

        return $order;
    }

    private function verifyReceipt(array $receipt): ReceiptResponse
    {
        $sharedSecret = $this->app->get(ConfigurationEnum::APPLE_PAYMENT_SHARED_SECRET->value);

        if (empty($sharedSecret)) {
            throw new ValidationException('No Apple Payment Shared Secret Configured');
        }

        $client = $this->runInSandbox ? ClientFactory::createSandbox() : ClientFactory::create();
        $verifier = new Verifier($client, $receipt['transactionReceipt'], $sharedSecret);

        return $verifier->verify(true, $this->runInSandbox ? $client : null);
    }

    private function createPeople(): People
    {
        return (new CreatePeopleFromUserAction(
            $this->app,
            $this->company->defaultBranch,
            $this->user
        ))->execute();
    }

    private function createOrderData(
        array $allReceiptData,
        People $people,
        array $orderItems,
    ): Order {
        // if ($receipt === null) {
        //     $exception = new ValidationException('Receipt validation failed: null receipt received');
        //     report($exception);

        //     throw $exception;
        // }

        // Get in-app purchases with detailed validation
        // $inAppPurchases = $receipt->getInApp();

        // if (empty($inAppPurchases)) {
        //     $exception = new ValidationException(
        //         'No in-app purchases found in receipt. This appears to be an app download receipt only.'
        //     );
        //     report($exception);

        //     throw $exception;
        // }

        // Validate we have the expected purchase
        // $firstPurchase = $inAppPurchases[0];

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
            fulfillmentStatus: 'pending',
            items: OrderItem::collect($orderItems, DataCollection::class),
            metadata: $allReceiptData,
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );
    }

    private function createOrderItem(Variants $variant): OrderItem
    {
        $warehouse = $this->region->warehouses()->firstOrFail();

        return new OrderItem(
            app: $this->app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->getProductId(),
            quantity: $variant->getQuantity($warehouse),
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

    private function calculateTotal(array|Collection $orderItems): float
    {
        return collect($orderItems)->sum(fn(OrderItem $item) => $item->quantity * $item->price);
    }
}
