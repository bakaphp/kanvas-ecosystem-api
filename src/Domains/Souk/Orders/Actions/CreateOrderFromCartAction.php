<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Darryldecode\Cart\Cart;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Spatie\LaravelData\DataCollection;

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
                zipcode: $this->billingAddress->zip
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
            fulfillmentStatus: 'pending',
            items: $items,
            metadata: '',
            weight: 0.0,
            checkoutToken: '',
            paymentGatewayName: ['manual'],
            languageCode: null,
        );

        return (new CreateOrderAction($order))->execute();
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
