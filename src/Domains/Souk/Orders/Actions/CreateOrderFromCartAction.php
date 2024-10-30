<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Darryldecode\Cart\Cart;
use Illuminate\Contracts\Auth\Authenticatable;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Spatie\LaravelData\DataCollection;

class CreateOrderFromCartAction
{
    public function __construct(
        private Cart $cart,
        private Companies $company,
        private Regions $region,
        private People $people,
        private Authenticatable $user,
        private Apps $app,
        private ?CreditCardBilling $billingAddress,
        private ?array $request
    ) {
    }

    public function execute(): array
    {

        if ($this->billingAddress !== null) {
            $billing = $this->people->addAddress(new Address(
                address: $this->billingAddress->address,
                address_2: null,
                city: $this->billingAddress->city,
                state: $this->billingAddress->state,
                country: $this->billingAddress->country,
                zipcode: $this->billingAddress->zip
            ));
        }

        if (!empty($this->cart)) {
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
                $totalTax = $lineItems[$key]->getTotalTax();
                $totalDiscount = $lineItems[$key]->getTotalDiscount();
            }
        }
        $items = $this->getOrderItems($this->cart->getContent()->toArray(), $this->app);

        $orderObject = new Order(
            app: $this->app,
            region: $this->region,
            company: $this->company,
            people: $this->people,
            user: $this->user ?? $this->company->user,
            email: $order->user->email ?? null,
            phone: $order->user->phone ?? null,
            token: '',
            shippingAddress: null,
            billingAddress: $billing ?? null,
            total: (float) $total,
            taxes: (float) $totalTax,
            totalDiscount: $totalDiscount,
            totalShipping: 0.0,
            status: 'completed',
            orderNumber: '',
            shippingMethod: null,
            currency: $this->region->currency,
            fulfillmentStatus: null,
            items: $items,
            metadata: '',
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: [],
            languageCode: null,
        );

        return $orderObject->toArray();
    }

    protected function getOrderItems($cartContent, $app): DataCollection
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
                quantity: $lineItem['quantity'],
                price: (float) $lineItem['price'],
                tax: $lineItem['tax'] ?? 0,
                discount: (float) ($lineItem['total_discount'] ?? 0),
                currency: Currencies::getByCode('USD'),
                quantityShipped: 0
            );
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }
}
