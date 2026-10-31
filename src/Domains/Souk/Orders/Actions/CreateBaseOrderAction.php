<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Orders\Models\OrderItem as OrderItemModel;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Kanvas\Souk\Shipping\Actions\RequoteCartShippingAction;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Kanvas\Souk\Taxes\Actions\FinalizeCheckoutTaxAction;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxComponent;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Spatie\LaravelData\DataCollection;
use Throwable;
use Wearepixel\Cart\Cart;

use function Sentry\captureMessage;

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
        new RequoteCartShippingAction(
            app: $this->app,
            company: $this->company,
            region: $this->region,
            cart: $this->cart,
        )->execute();

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

        $hasItemsInCart = ! $this->cart->isEmpty();
        $totalShipping = 0.0;
        if ($hasItemsInCart) {
            $total = $this->cart->getSubTotalWithoutConditions();

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
                $lineItems[$key] = OrderItem::from(
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
        $providerShipping = $hasItemsInCart ? RequoteCartShippingAction::providerSelection($this->cart) : [];

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
            shippingMethod: $providerShipping[ShippingConditionEnum::METHOD_NAME->value] ?? null,
            currency: $orderCurrency,
            fulfillmentStatus: OrderStatusEnum::PENDING->value,
            items: $items,
            orderType: $this->request['input']['order_type'] ?? null,
            metadata: $this->buildOrderMetadata($providerShipping),
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
            reference: $this->request['input']['reference'] ?? '',
            paymentStatus: 'unpaid',
            parent: $this->parent,
            ipAddress: $this->ipAddress,
            estimateShippingDate: $providerShipping[ShippingConditionEnum::ESTIMATE_SHIPPING_DATE->value] ?? null,
            chargeShipping: $providerShipping !== [],
        );

        $order = new CreateOrderAction($order)->disableWorkflow()->execute();

        $this->saveOrderDiscountsFromCart($order);

        $this->applyCompanyCredit($order);

        $order->refresh();
        $this->attachTaxSnapshot($order);

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

    protected function attachTaxSnapshot(ModelsOrder $order): void
    {
        $chargedIntentId = $this->chargedPaymentIntentId();

        if ($chargedIntentId !== null && $order->payment_intent_id === null) {
            $order->payment_intent_id = $chargedIntentId;
            $order->saveOrFail();
        }

        $snapshot = $this->resolveSnapshotForOrder($order);

        if ($snapshot === null) {
            if ($chargedIntentId !== null) {
                return;
            }

            new FinalizeCheckoutTaxAction(
                order: $order,
                app: $this->app,
                reservesCredit: false,
            )->execute();

            return;
        }

        $storedHash = $snapshot->input_hash;
        $payloadHash = $snapshot->checkout_payload['input_hash'] ?? null;

        if ($payloadHash !== null && $payloadHash !== $storedHash) {
            $order->tax_status = TaxStatusEnum::MISMATCH->value;
            $order->status = OrderStatusEnum::ON_HOLD->value;
            $order->saveOrFail();

            captureMessage("Tax hash mismatch on order {$order->id}");

            return;
        }

        $snapshot->load('components');

        DB::connection('commerce')->transaction(function () use ($snapshot, $order): void {
            $this->rekeyComponentLineIds($snapshot, $order);
            $snapshot->order_id = $order->id;
            $snapshot->save();
        });

        $order->tax_amount = (float) $snapshot->tax_amount;
        $order->tax_source = 'souk';
        $order->recomputeTotals(true);

        $payable = (float) $order->total_payable_amount;
        $snapshotPayable = (float) ($snapshot->checkout_payload['totals']['payable'] ?? 0.0);

        if (abs($payable - $snapshotPayable) > 0.01) {
            Log::warning('Tax snapshot amount mismatch', [
                'order_id' => $order->id,
                'order_total_payable' => $payable,
                'snapshot_total_payable' => $snapshotPayable,
                'snapshot_uuid' => $snapshot->uuid,
            ]);
        }
    }

    protected function resolveSnapshotForOrder(ModelsOrder $order): ?OrderTaxSnapshot
    {
        $paymentIntentId = $order->payment_intent_id;

        if ($paymentIntentId !== null) {
            $snapshot = OrderTaxSnapshot::query()
                ->where('apps_id', $this->app->getId())
                ->where('payment_intent_id', $paymentIntentId)
                ->where('status', TaxStatusEnum::CALCULATED->value)
                ->latest()
                ->first();

            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        $cartSessionId = $order->checkout_token;

        if ($cartSessionId === null || $cartSessionId === '') {
            return null;
        }

        return OrderTaxSnapshot::query()
            ->where('apps_id', $this->app->getId())
            ->where('cart_session_id', $cartSessionId)
            ->where('status', TaxStatusEnum::CALCULATED->value)
            ->whereNull('order_id')
            ->latest()
            ->first();
    }

    private function rekeyComponentLineIds(OrderTaxSnapshot $snapshot, ModelsOrder $order): void
    {
        $payloadLines = $snapshot->checkout_payload['lines'] ?? [];

        if (empty($payloadLines)) {
            return;
        }

        $itemUuidByVariantUuid = OrderItemModel::query()
            ->with('variant')
            ->where('order_id', $order->id)
            ->get()
            ->filter(fn (OrderItemModel $item) => $item->variant !== null)
            ->mapWithKeys(fn (OrderItemModel $item) => [$item->variant->uuid => $item->uuid]);

        foreach ($snapshot->components as $component) {
            $itemUuid = $itemUuidByVariantUuid->get($component->line_id);

            if ($itemUuid !== null) {
                OrderTaxComponent::query()->where('id', $component->id)->update(['line_id' => $itemUuid]);
            }
        }
    }

    protected function getOrderItems(array $cartContent, AppInterface $app, Currencies $currency): DataCollection
    {
        $orderItems = [];

        foreach ($cartContent as $lineItem) {
            $variant = Variants::getById($lineItem['id']);

            $productAttributes = $variant->product->attributes
                ? $variant->product->attributes->pluck('name')->toArray()
                : [];

            $customAttributes = [];
            if (isset($lineItem['attributes']) && is_array($lineItem['attributes'])) {
                foreach ($lineItem['attributes'] as $attributeName => $attributeValue) {
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
                metadata: ! empty($customAttributes) ? $customAttributes : null,
                channelId: ! empty($lineItem['attributes']['channel_id']) ? (int) $lineItem['attributes']['channel_id'] : null
            );
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }

    protected function buildOrderMetadata(array $providerShipping): mixed
    {
        $metadata = $this->request['input']['metadata'] ?? [];

        if ($providerShipping === []) {
            return $metadata;
        }

        $metadata = Str::jsonToArray($metadata);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['shipping'] = [
            ShippingConditionEnum::PROVIDER->value => $providerShipping[ShippingConditionEnum::PROVIDER->value],
            ShippingConditionEnum::SERVICE->value => $providerShipping[ShippingConditionEnum::SERVICE->value] ?? null,
            ShippingConditionEnum::QUOTED_AMOUNT->value => $providerShipping[ShippingConditionEnum::QUOTED_AMOUNT->value] ?? null,
            ShippingConditionEnum::CURRENCY->value => $providerShipping[ShippingConditionEnum::CURRENCY->value] ?? null,
            ShippingConditionEnum::QUOTED_AT->value => $providerShipping[ShippingConditionEnum::QUOTED_AT->value] ?? null,
        ];

        return $metadata;
    }

    protected function calculateTotalFromConditions(string $type): float
    {
        $total = 0.0;
        $conditions = $this->cart->getConditions();
        $subtotal = $this->cart->getSubTotalWithoutConditions();

        foreach ($conditions as $condition) {
            if ($condition->getType() === $type) {
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

    protected function applyCompanyCredit(ModelsOrder $order): void
    {
        if ($this->isPrepaidAtCheckout($order)) {
            return;
        }

        try {
            new DiscountService($order->app, $order->buyerCompany())->applyFirstAvailableCredit($order);
        } catch (ValidationException) {
            // expected skip, not a fault
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function chargedPaymentIntentId(): ?string
    {
        return null;
    }

    protected function isPrepaidAtCheckout(ModelsOrder $order): bool
    {
        return $order->payments()->exists()
            || $this->cart->getConditions()->contains(fn ($condition) => $condition->getType() === 'wallet');
    }

    protected function saveOrderDiscountsFromCart(ModelsOrder $order): void
    {
        $conditions = $this->cart->getConditions();

        $discountService = new DiscountService(
            $order->app,
            $order->company
        );

        foreach ($conditions as $condition) {
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

    protected function processWalletCreditFromCart(ModelsOrder $order): void
    {
        $conditions = $this->cart->getConditions();

        foreach ($conditions as $condition) {
            if ($condition->getType() !== 'wallet') {
                continue;
            }

            $attributes = $condition->getAttributes();
            $walletTag = $attributes['wallet_tag'] ?? 'default';
            $appliedAmount = $attributes['applied_amount'] ?? 0;

            if ($appliedAmount <= 0) {
                continue;
            }

            $wallet = $order->user->createAppWallet($this->app, ['name' => $walletTag]);

            if ($wallet->balanceFloat < $appliedAmount) {
                throw new Exception(
                    'Insufficient wallet balance. Required: ' . $appliedAmount .
                    ', Available: ' . $wallet->balanceFloat
                );
            }

            $wallet->withdrawFloat($appliedAmount, [
                'order_id' => $order->getId(),
                'order_number' => $order->number,
                'type' => 'order_payment',
                'description' => 'Wallet credit applied to order #' . $order->number,
            ]);

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
        }
    }
}
