<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Spatie\LaravelData\DataCollection;
use Wearepixel\Cart\Cart;

class CreateOrderFromCartAction
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
    ) {
    }

    public function execute(): ModelsOrder
    {
        if ($this->billingAddress !== null) {
            $billing = $this->people->addAddress(new Address(
                address: $this->billingAddress->address,
                address_2: null,
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
                address_2: null,
                city: $this->shippingAddress->city,
                state: $this->shippingAddress->state,
                country: $this->shippingAddress->country,
                zip: $this->shippingAddress->zip,
                address_type_id: AddressType::getByName(AddressTypeEnum::SHIPPING->value, $this->app)->getId()
            ));
        }

        if (! empty($this->cart)) {
            $total = $this->cart->getTotal();
            $totalTax = ($this->cart->getTotal()) - ($this->cart->getSubTotal());
            $totalDiscount = 0.0;
        } else {
            $total = 0;
            $totalTax = 0;
            $totalDiscount = 0;
            $lineItems = [];
            foreach ($this->request['input']['items'] as $key => $lineItem) {
                $lineItems[$key] = OrderItem::viaRequest($this->app, $this->company, $this->region, $lineItem);
                $total += $lineItems[$key]->getTotal();
                $totalTax += $lineItems[$key]->getTotalTax();
                $totalDiscount = $lineItems[$key]->getTotalDiscount();
            }
        }
        $items = $this->getOrderItems($this->cart->getContent()->toArray(), $this->app);

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
            total: (float) $total,
            taxes: (float) $totalTax,
            totalDiscount: $totalDiscount,
            totalShipping: 0.0,
            status: 'completed',
            orderNumber: '',
            shippingMethod: null,
            currency: $this->region->currency,
            fulfillmentStatus: 'pending',
            items: $items,
            metadata: $this->request['input']['metadata'] ?? [],
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );

        $order = (new CreateOrderAction($order))->execute();

        $this->cart->clear();

        return $order;
    }

    protected function getOrderItems(array $cartContent, AppInterface $app): DataCollection
    {
        $orderItems = [];

        foreach ($cartContent as $lineItem) {
            $variant = Variants::getById($lineItem['id']);

            //this shouldn't happen but just in case
            if (! $variant) {
                continue;
            }

            $orderItems[] = new OrderItem(
                app: $app,
                variant: $variant,
                name: $lineItem['name'],
                sku: (string) ($variant->sku ?? $lineItem['id']),
                quantity: (int) $lineItem['quantity'],
                price: (float) $lineItem['price'],
                tax: (float) ($lineItem['tax'] ?? 0),
                discount: (float) ($lineItem['total_discount'] ?? 0),
                currency: Currencies::getByCode('USD'),
                quantityShipped: 0
            );
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }
}
