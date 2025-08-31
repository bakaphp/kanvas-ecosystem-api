<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Spatie\LaravelData\DataCollection;

abstract class CreateOrderFromReceiptActionBase
{
    protected AppInterface $app;
    protected CompanyInterface $company;
    protected UserInterface $user;
    protected Regions $region;

    /**
     * @throws ValidationException
     */
    abstract public function execute(): ModelsOrder;

    /**
     * @throws ValidationException
     */
    abstract protected function verifyReceipt(array $receipt): mixed;

    /**
     * Get the custom fields from the specific in-app purchase DTO.
     */
    abstract protected function getCustomFields(): array;

    protected function createPeople(): People
    {
        return (new CreatePeopleFromUserAction(
            $this->app,
            $this->company->defaultBranch,
            $this->user
        ))->execute();
    }

    protected function createOrderItem(Variants $variant, int $quantity): OrderItem
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

    protected function getVariant(string $sku): Variants
    {
        return Variants::getBySku($sku, $this->company, $this->app);
    }

    /**
     * @param array<OrderItem> $orderItems
     */
    protected function calculateTotal(array $orderItems): float
    {
        return array_reduce($orderItems, fn (float $total, OrderItem $item): float =>
            $total + ((float) $item->quantity * $item->price), 0.0);
    }

    protected function processCustomFieldsVariants(array &$orderItems): void
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
        $customFields = $this->getCustomFields();

        if (! empty($customFields)) {
            foreach ($customFields as $field) {
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

    /**
     * Create Order DTO with common parameters.
     *
     * @param array<OrderItem> $orderItems
     */
    protected function createOrderDto(
        array $orderItems,
        People $people,
        array $metadata
    ): Order {
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
            metadata: $metadata,
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );
    }

    /**
     * Handle custom fields assignment after order creation.
     */
    protected function handleCustomFieldsOnOrder(ModelsOrder $order): void
    {
        $customFields = $this->getCustomFields();

        if (! empty($customFields)) {
            $order->setCustomFields($customFields);
            $order->saveCustomFields();
        }
    }
}
