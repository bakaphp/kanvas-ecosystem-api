<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Spatie\LaravelData\DataCollection;
use Wearepixel\Cart\Cart;

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
                $lineItems[$key] = OrderItem::viaRequest(
                    $this->app,
                    $this->company,
                    $this->region,
                    $lineItem
                );
                $total += $lineItems[$key]->getTotal();
                $totalTax += $lineItems[$key]->getTotalTax();
                $totalDiscount = 0.0;
            }

            $lineItems = OrderItem::collect($lineItems, DataCollection::class);
        }

        $orderCurrency = $this->resolveOrderCurrency();

        $items = $hasItemsInCart
            ? $this->getOrderItems($lineItems, $this->app, $orderCurrency)
            : $lineItems;

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
            currency: $orderCurrency,
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

        $order = new CreateOrderAction($order)->disableWorkflow()->execute();

        // Save the order discounts from cart conditions
        $this->saveOrderDiscountsFromCart($order);

        // Process wallet credit if applied
        $this->processWalletCreditFromCart($order);

        $order->fireWorkflow(
            event: WorkflowEnum::CREATED->value,
            async: true,
            params: [
               'app' => $order->app,
            ]
        );

        try {
            $userCompany = $order->user->getCurrentCompany();
            $orderCompany = $order->company;

            if ($userCompany->getId() == $orderCompany->getId()) {
                new SendUserNotificationAction(
                    $order->app,
                    $this->company,
                    $order->user
                )->execute('admin-new-order', [
                    'order' => $order,
                ]);
            }
        } catch (Exception $e) {
            report($e);
        }

        $order->refresh();

        $this->cart->clear();

        return $order;
    }

    protected function getOrderItems(array $cartContent, AppInterface $app, Currencies $currency): DataCollection
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
                currency: $currency,
                quantityShipped: 0,
                metadata: ! empty($customAttributes) ? $customAttributes : null, // Only custom attributes, not product attributes
                channelId: ! empty($lineItem['attributes']['channel_id']) ? (int) $lineItem['attributes']['channel_id'] : null
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

    protected function resolveOrderCurrency(): Currencies
    {
        try {
            if (isset($this->request['input']['currency']) && ! empty($this->request['input']['currency'])) {
                return Currencies::getByCode($this->request['input']['currency']);
            }
        } catch (ModelNotFoundException $e) {
            // Fallback below.
        }

        return $this->region->currency;
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

    /**
     * Process wallet credit from cart conditions and deduct from wallet.
     */
    protected function processWalletCreditFromCart(ModelsOrder $order): void
    {
        // Get all conditions from the cart
        $conditions = $this->cart->getConditions();

        foreach ($conditions as $condition) {
            // Only process wallet type conditions
            if ($condition->getType() !== 'wallet') {
                continue;
            }

            $attributes = $condition->getAttributes();
            $walletTag = $attributes['wallet_tag'] ?? 'default';
            $appliedAmount = $attributes['applied_amount'] ?? 0;

            if ($appliedAmount <= 0) {
                continue;
            }

            // Get the company wallet
            $wallet = $order->user->createAppWallet($this->app, ['name' => $walletTag]);

            // Verify sufficient balance
            if ($wallet->balanceFloat < $appliedAmount) {
                throw new Exception(
                    'Insufficient wallet balance. Required: ' . $appliedAmount .
                    ', Available: ' . $wallet->balanceFloat
                );
            }

            // Withdraw the amount from the wallet
            $wallet->withdrawFloat($appliedAmount, [
                'order_id' => $order->getId(),
                'order_number' => $order->number,
                'type' => 'order_payment',
                'description' => 'Wallet credit applied to order #' . $order->number,
            ]);

            // Store wallet transaction info in order metadata
            $order->addMetadata(WalletConfigurationEnum::WALLET_CREDIT->value, [
                'tag' => $walletTag,
                'amount' => $appliedAmount,
                'transaction_id' => $wallet->getKey(),
                'applied_at' => now()->toIso8601String(),
            ]);

            $order->set(WalletConfigurationEnum::WALLET_CREDIT->value, [
                'tag' => $walletTag,
                'amount' => $appliedAmount,
                'transaction_id' => $wallet->getKey(),
                'applied_at' => now()->toIso8601String(),
            ]);

            $order->addTag(WalletConfigurationEnum::WALLET_CREDIT_TAG->value);

            //@todo , need to add it to the order logs
        }
    }
}
