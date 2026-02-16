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
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Laravel\Cashier\Subscription as CashierSubscription;
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
            status: OrderStatusEnum::COMPLETED->value,
            orderNumber: '',
            shippingMethod: null,
            currency: $this->region->currency,
            fulfillmentStatus: OrderFulfillmentStatusEnum::PENDING->value,
            items: OrderItem::collect($orderItems, DataCollection::class),
            metadata: $metadata,
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['iap'],
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

    protected function addMessageMetadataFromCustomFields(array &$allReceiptData): void
    {
        if (! isset($allReceiptData['custom_fields']) || ! is_array($allReceiptData['custom_fields'])) {
            return;
        }

        $messageId = $this->extractMessageIdFromCustomFields($allReceiptData['custom_fields']);
        if ($messageId === null) {
            return;
        }

        $message = Message::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('id', $messageId)
            ->first();

        if (! $message) {
            return;
        }

        $messageContent = $message->getMessage();
        $messageUser = $message->user;

        $allReceiptData['message'] = [
            'users_id' => $messageUser?->getId(),
            'creator_display_name' => $messageUser?->displayname,
            'creator_email' => $messageUser?->email,
            'id' => $message->getId(),
            'user_subscription_tier' => $message->getId(),
            'prompt_title' => (string) ($messageContent['title'] ?? $message->slug),
        ];
    }

    protected function extractMessageIdFromCustomFields(array $customFields): ?int
    {
        if (array_key_exists('message_id', $customFields)) {
            $messageId = filter_var($customFields['message_id'], FILTER_VALIDATE_INT);

            return $messageId === false ? null : $messageId;
        }

        foreach ($customFields as $field) {
            if (
                is_array($field)
                && ($field['name'] ?? null) === 'message_id'
                && array_key_exists('value', $field)
            ) {
                $messageId = filter_var($field['value'], FILTER_VALIDATE_INT);

                return $messageId === false ? null : $messageId;
            }
        }

        return null;
    }

    protected function findExistingOrderByAppleTransactionId(string $transactionId): ?ModelsOrder
    {
        return ModelsOrder::fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->whereJsonContains('metadata->apple_subscription->transaction_id', $transactionId)
            ->orderByDesc('id')
            ->first();
    }

    protected function findExistingAppleSubscriptionOrder(string $transactionId): ?ModelsOrder
    {
        return $this->findExistingOrderByAppleTransactionId($transactionId);
    }

    protected function findExistingOrderByGoogleSubscriptionOrderId(string $orderId): ?ModelsOrder
    {
        return ModelsOrder::fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->whereJsonContains('metadata->google_subscription->order_id', $orderId)
            ->orderByDesc('id')
            ->first();
    }

    protected function findAppleCashierSubscription(
        int $appsStripeCustomerId,
        string $originalTransactionId
    ): ?CashierSubscription {
        $existingSubscription = CashierSubscription::query()
            ->where('stripe_id', $originalTransactionId)
            ->first();

        if ($existingSubscription instanceof CashierSubscription) {
            return $existingSubscription;
        }

        return CashierSubscription::query()
            ->where('apps_stripe_customer_id', $appsStripeCustomerId)
            ->where('stripe_id', $originalTransactionId)
            ->first();
    }
}
