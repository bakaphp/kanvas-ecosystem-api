<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Automattic\WooCommerce\Client as WooCommerceClient;
use Kanvas\Connectors\WooCommerce\Client;
use Kanvas\Connectors\WooCommerce\Enums\CustomFieldEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class PushOrderToWooCommerce
{
    protected WooCommerceClient $wooCommerceService;

    public function __construct(
        protected Order $order
    ) {
        $this->wooCommerceService = (new Client($this->order->app))->getClient();
    }

    public function execute(): object
    {
        // Format the order data for WooCommerce
        $orderData = $this->formatOrderData();

        // Send the order to WooCommerce
        return $this->wooCommerceService->post('orders', $orderData);
    }

    /**
     * Format the order data for WooCommerce
     */
    protected function formatOrderData(): array
    {
        //$billingAddress = $this->order->getBillingAddress();
        // $shippingAddress = $this->order->getShippingAddress() ?? $billingAddress;

        // Get customer information
        $customer = $this->order->people;

        // Get line items
        $lineItems = $this->getLineItems();

        $orderData = [
            'status' => $this->mapOrderStatus($this->order->status),
            'currency' => $this->order->currency->code,
            'customer_id' => $customer->get(CustomFieldEnum::WOOCOMMERCE_ID->value) ?? 0, // If you have WooCommerce customer ID stored
           // 'billing' => $this->formatAddress($billingAddress, $customer),
          //  'shipping' => $this->formatAddress($shippingAddress, $customer),
            'line_items' => $lineItems,
           // 'shipping_lines' => $this->getShippingLines(),
            'payment_method' => $this->order->payment->payment_method ?? '',
            'payment_method_title' => $this->order->payment->payment_method_title ?? '',
            'meta_data' => [
                [
                    'key' => 'kanvas_order_id',
                    'value' => $this->order->id,
                ],
            ],
        ];

        if ($this->order->billing_address_id !== null) {
            $orderData['billing'] = $this->formatAddress($this->order->billingAddress, $customer);
        }

        if ($this->order->shipping_address_id !== null) {
            $orderData['shipping'] = $this->formatAddress($this->order->shippingAddress, $customer);
        }

        return $orderData;
    }

    protected function formatAddress(Address $address, People $customer): array
    {
        $country = $address->country;

        return [
            'first_name' => $customer->firstname,
            'last_name' => $customer->lastname,
            'address_1' => $address->address,
            'address_2' => $address->address_2 ?? '',
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->zip,
            'country' => $country->code ?? '',
            'email' => $this->order->user_email,
            'phone' => $this->order->user_phone ?? '',
        ];
    }

    /**
     * Get order line items
     */
    protected function getLineItems(): array
    {
        $lineItems = [];

        foreach ($this->order->items as $item) {
            $item = [
                'quantity' => $item->quantity,
                'price' => $item->unit_price_net_amount,
                'total' => (string)((float) $item->quantity * (float) $item->unit_price_net_amount),
                'sku' => $item->product_sku ?? '',
                'meta_data' => [
                    [
                        'key' => 'kanvas_product_id',
                        'value' => $item->variant_id,
                    ],
                ],
            ];

            // Add product_id if available
            /*  if (! empty($product->product->external_id)) {
                 $item['product_id'] = $product->product->external_id;
             }
 */
            $lineItems[] = $item;
        }

        return $lineItems;
    }

    /**
     * Get shipping lines
     */
    protected function getShippingLines(): array
    {
        if (! $this->order->shipping_amount) {
            return [];
        }

        return [
            [
                'method_id' => 'flat_rate',
                'method_title' => 'Shipping',
                'total' => (string) $this->order->shipping_amount,
            ],
        ];
    }

    /**
     * Map Kanvas order status to WooCommerce order status
     */
    protected function mapOrderStatus(string $status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'processing' => 'processing',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            'on-hold' => 'on-hold',
            // Add other status mappings as needed
        ];

        return $statusMap[$status] ?? 'pending';
    }
}
