<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Joelwmale\Cart\Cart;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Spatie\LaravelData\DataCollection;

class CreateBaseOrderAction
{
    public function __construct(
        protected Cart $cart,
        protected Companies $company,
        protected Regions $region,
        protected OrderCustomer $orderCustomer,
        protected People $people,
        protected UserInterface $user,
        protected Apps $app,
        protected ?CreditCardBilling $billingAddress,
        protected ?Address $shippingAddress,
        protected ?array $request,
        protected ?ModelsOrder $parent = null,
        protected ?string $ipAddress = null,
    ) {
    }

    public function execute(): ModelsOrder
    {
        if ($this->billingAddress !== null) {
            $billing = $this->people->addAddress(new Address(
                address: $this->billingAddress->address,
                address_2: $this->billingAddress->address2,
                city: $this->billingAddress->city,
                state: $this->billingAddress->state,
                country: $this->billingAddress->country,
                zip: $this->billingAddress->zip,
                address_type_id: AddressType::getByName(AddressTypeEnum::BILLING->value, $this->app)->getId()
            ));
        }

        if ($this->shippingAddress !== null) {
            $shipping = $this->people->addAddress(new Address(
                address: $this->shippingAddress->address,
                address_2: $this->shippingAddress->address_2,
                city: $this->shippingAddress->city,
                state: $this->shippingAddress->state,
                country: $this->shippingAddress->country,
                zip: $this->shippingAddress->zip,
                address_type_id: AddressType::getByName(AddressTypeEnum::SHIPPING->value, $this->app)->getId()
            ));
        }

        $hasItemsInCart = ! $this->cart->isEmpty(); //&& $this->cart->getTotal() > 0;
        $totalShipping = 0.0;
        if ($hasItemsInCart) {
            $total = $this->cart->getSubTotalWithoutConditions();

            // Calculate totals from cart conditions
            $totalTax = $this->calculateTotalFromConditions('tax');
            $totalShipping = $this->calculateTotalFromConditions('shipping');
            $totalDiscount = 0.0;

            $lineItems = $this->cart->getContent()->toArray();
        } else {
            $total = 0;
            $totalTax = 0;
            $totalDiscount = 0;
            $lineItems = [];

            foreach ($this->request['input']['items'] as $key => $lineItem) {
                $lineItems[$key] = OrderItem::viaRequest($this->app, $this->company, $this->region, $lineItem);
                $total += $lineItems[$key]->getTotal();
                $totalTax += $lineItems[$key]->getTotalTax();
                $totalDiscount = 0.0;
            }

            $lineItems = OrderItem::collect($lineItems, DataCollection::class);
        }

        $items = $hasItemsInCart ? $this->getOrderItems($lineItems, $this->app) : $lineItems;

        try {
            $currency = isset($this->request['input']['currency']) && ! empty($this->request['input']['currency']) ? Currencies::getByCode($this->request['input']['currency']) : $this->region->currency;
        } catch (ModelNotFoundException $e) {
            $currency = $this->region->currency;
        }

        $order = new Order(
            app: $this->app,
            region: $this->region,
            company: $this->company,
            people: $this->people,
            user: $this->user ?? $this->company->user,
            email: $this->orderCustomer->email,
            phone: $this->orderCustomer->phone,
            token: Str::random(32),
            shippingAddress: $shipping ?? null,
            billingAddress: $billing ?? null,
            total: $total,
            taxes: $totalTax,
            totalDiscount: $totalDiscount,
            totalShipping: $totalShipping,
            status: OrderStatusEnum::COMPLETED->value,
            orderNumber: '',
            shippingMethod: null,
            currency: $currency,
            fulfillmentStatus: OrderStatusEnum::PENDING->value,
            items: $items,
            orderType: $this->request['input']['order_type'] ?? null,
            metadata: $this->request['input']['metadata'] ?? [],
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
            reference: $this->request['input']['reference'] ?? '',
            paymentStatus: 'unpaid',
            parent: $this->parent,
            ipAddress: $this->ipAddress,
        );

        $order = (new CreateOrderAction($order))->execute();

        // Save the order discounts from cart conditions
        $this->saveOrderDiscountsFromCart($order);

        //@todo remove this we already have it on create order action
        new SendUserNotificationAction(
            $order->app,
            $this->company,
            $order->user
        )->execute('admin-new-order', [
            'order' => $order,
        ]);

        $this->cart->clear();

        return $order;
    }

    protected function getOrderItems(array $cartContent, AppInterface $app): DataCollection
    {
        $orderItems = [];

        foreach ($cartContent as $lineItem) {
            $variant = Variants::getById($lineItem['id']);

            // Get the product's default attributes to exclude them from metadata
            $productAttributes = $variant->product->attributes
                ? $variant->product->attributes->pluck('name')->toArray()
                : [];

            // Filter out product attributes from cart attributes, keeping only custom attributes
            $customAttributes = [];
            if (isset($lineItem['attributes']) && is_array($lineItem['attributes'])) {
                foreach ($lineItem['attributes'] as $attributeName => $attributeValue) {
                    // Only include attributes that are NOT part of the product's default attributes
                    if (! in_array($attributeName, $productAttributes)) {
                        $customAttributes[$attributeName] = $attributeValue;
                    }
                }
            }

            $orderItems[] = new OrderItem(
                app: $app,
                variant: $variant,
                name: (string) $lineItem['name'],
                sku: (string) ($variant->sku ?? $lineItem['id']),
                quantity: (float) $lineItem['quantity'],
                price: (float) $lineItem['price'],
                tax: (float) ($lineItem['tax'] ?? 0),
                discount: (float) ($lineItem['total_discount'] ?? 0),
                currency: Currencies::getByCode('USD'),
                quantityShipped: 0,
                metadata: ! empty($customAttributes) ? $customAttributes : null, // Only custom attributes, not product attributes
            );
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }

    /**
     * Calculate total amount for a specific condition type from cart.
     */
    protected function calculateTotalFromConditions(string $type): float
    {
        $total = 0.0;
        $conditions = $this->cart->getConditions();
        $subtotal = $this->cart->getSubTotalWithoutConditions();

        foreach ($conditions as $condition) {
            if ($condition->getType() === $type) {
                // Get the calculated value of the condition
                $value = (float) $condition->getCalculatedValue($subtotal);

                $total += $value;
            }
        }

        return $total;
    }

    /**
     * Save order discounts from cart conditions.
     */
    protected function saveOrderDiscountsFromCart(ModelsOrder $order): void
    {
        // Get all discount conditions from the cart
        $conditions = $this->cart->getConditions();

        $discountService = new DiscountService(
            $order->app,
            $order->company
        );

        foreach ($conditions as $condition) {
            // Only process discount type conditions
            if ($condition->getType() !== 'discount') {
                continue;
            }

            $attributes = $condition->getAttributes();

            $discountCode = $attributes['discount_code'] ?? null;

            if ($discountCode === null) {
                continue;
            }

            try {
                $discountService->applyDiscountCode($discountCode, $order);
            } catch (Exception $e) {
                report($e);
            }
        }
    }
}
