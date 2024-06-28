<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Notifications\NewManualPaidOrderNotification;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Users\Models\UsersAssociatedApps;
use Spatie\LaravelData\DataCollection;

class SyncShopifyOrderAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected array $orderData
    ) {
    }

    public function execute(): ModelsOrder
    {
        $customer = $this->syncCustomer();
        $this->syncProducts();

        $shippingAddress = $customer->addAddress(new Address(
            address: $this->orderData['shipping_address']['address1'],
            address_2: $this->orderData['shipping_address']['address2'],
            city: $this->orderData['shipping_address']['city'],
            state: $this->orderData['shipping_address']['province'],
            country: $this->orderData['shipping_address']['country'],
            zipcode: $this->orderData['shipping_address']['zip']
        ));

        $billingAddress = $customer->addAddress(new Address(
            address: $this->orderData['billing_address']['address1'],
            address_2: $this->orderData['billing_address']['address2'],
            city: $this->orderData['billing_address']['city'],
            state: $this->orderData['billing_address']['province'],
            country: $this->orderData['billing_address']['country'],
            zipcode: $this->orderData['billing_address']['zip']
        ));

        $user = UsersAssociatedApps::fromApp($this->app)->where('email', $this->orderData['contact_email'])?->first()?->user;

        $order = new Order(
            app: $this->app,
            region: $this->region,
            company: $this->company,
            people: $customer,
            user: $user ?? $this->company->user,
            email: $this->orderData['contact_email'],
            phone: $this->orderData['phone'],
            token: $this->orderData['token'],
            shippingAddress: $shippingAddress,
            billingAddress: $billingAddress,
            total: (float) $this->orderData['current_total_price'],
            taxes: (float)  $this->orderData['current_total_tax'],
            totalDiscount: (float)  $this->orderData['total_discounts'],
            totalShipping: (float)   $this->orderData['total_shipping_price_set']['shop_money']['amount'],
            status: 'completed',
            orderNumber: (string) $this->orderData['order_number'],
            shippingMethod: $this->orderData['shipping_lines'][0]['title'],
            currency: Currencies::getByCode($this->orderData['currency']),
            items: $this->getOrderItems(),
            metadata: json_encode($this->orderData),
            weight: $this->orderData['total_weight'],
            checkoutToken: (string) $this->orderData['id'],
            paymentGatewayName: $this->orderData['payment_gateway_names'],
        );

        $orderExist = ModelsOrder::getByCustomField(
            ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_ORDER_ID->value, $this->company, $this->app, $this->region),
            $this->orderData['id'],
            $this->company
        );

        if ($orderExist) {
            return $orderExist;
        }

        $order = (new CreateOrderAction($order))->execute();
        $order->setShopifyId($this->region, $this->orderData['id']);

        /**
         * @todo move to workflow
         */
        if (in_array($this->orderData['payment_gateway_names'], ['manual'])) {
            $customer->notify(new NewManualPaidOrderNotification($order));
        }

        return $order;
    }

    protected function syncCustomer(): People
    {
        $syncCustomer = new SyncShopifyCustomerAction(
            $this->app,
            $this->company,
            $this->region,
            $this->orderData['customer']
        );

        return $syncCustomer->execute();
    }

    protected function syncProducts(): void
    {
        foreach ($this->orderData['line_items'] as $lineItem) {
            $syncProduct = new SyncShopifyProductAction(
                $this->app,
                $this->company,
                $this->region,
                $lineItem['product_id']
            );

            $syncProduct->execute();
        }
    }

    protected function getOrderItems(): DataCollection
    {
        $orderItems = [];

        foreach ($this->orderData['line_items'] as $lineItem) {
            $shopifyVariantKey = ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_VARIANT_ID->value, $this->company, $this->app, $this->region);
            $variant = Variants::getByCustomField(
                $shopifyVariantKey,
                $lineItem['variant_id'],
                $this->company
            );

            //this shouldn't happen but just in case
            if (! $variant) {
                continue;
            }

            $orderItems[] = new OrderItem(
                app: $this->app,
                variant: $variant,
                name: $lineItem['name'],
                sku: (string) ($lineItem['sku'] ?? $lineItem['variant_id']),
                quantity: $lineItem['quantity'],
                price: (float) $lineItem['price'],
                tax: $lineItem['tax'] ?? 0,
                discount: (float) ($lineItem['total_discount'] ?? 0),
                currency: Currencies::getByCode($lineItem['price_set']['shop_money']['currency_code']),
                quantityShipped: 0
            );
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }
}
