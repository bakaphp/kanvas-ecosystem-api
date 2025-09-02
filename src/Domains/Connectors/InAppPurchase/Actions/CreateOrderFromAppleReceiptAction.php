<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Imdhemy\AppStore\ClientFactory;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Imdhemy\AppStore\Receipts\Verifier;
use Imdhemy\AppStore\ValueObjects\Receipt;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Override;

class CreateOrderFromAppleReceiptAction extends CreateOrderFromReceiptActionBase
{
    private const DEFAULT_CURRENCY = 'USD';

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
        $receipt = [
            'productId' => $this->appleInAppPurchase->product_id,
            'transactionId' => $this->appleInAppPurchase->transaction_id,
            'transactionReceipt' => $this->appleInAppPurchase->receipt,
            'transactionDate' => $this->appleInAppPurchase->transaction_date,
            'custom_fields' => $this->appleInAppPurchase->custom_fields,
        ];

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

        $order = (new CreateOrderAction($orderData))->execute();

        $this->handleCustomFieldsOnOrder($order);

        return $order;
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

        // Validate we have the expected purchase
        $firstPurchase = $inAppPurchases[0];
        $firstVariant = $this->getVariant($firstPurchase->getProductId());
        /** @var array<OrderItem> $orderItems */
        $orderItems = [];

        $orderItem = $this->createOrderItem(
            $firstVariant,
            $firstPurchase->getQuantity()
        );

        $orderItems[] = $orderItem;

        $this->processCustomFieldsVariants($orderItems);

        return $this->createOrderDto($orderItems, $people, $allReceiptData);
    }
}
