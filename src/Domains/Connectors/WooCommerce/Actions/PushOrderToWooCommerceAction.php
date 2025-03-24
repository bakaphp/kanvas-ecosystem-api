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

class PushOrderToWooCommerceAction
{
    protected WooCommerceClient $wooCommerceService;

    public function __construct(
        protected Order $order,
        protected array $customMetadata = [],
    ) {
        $this->wooCommerceService = (new Client($this->order->app))->getClient();
        $this->customMetadata = $customMetadata;
    }

    public function execute(): object
    {
        // Format the order data for WooCommerce (including all eSIM data)
        $orderData = $this->formatOrderData();

        // Send the order to WooCommerce with all data in a single request
        $woocommerceOrder = $this->wooCommerceService->post('orders', $orderData);

        return $woocommerceOrder;
    }

    /**
     * Format the order data for WooCommerce
     */
    protected function formatOrderData(): array
    {
        // Get customer information
        $customer = $this->order->people;

        // Get line items
        $lineItems = $this->getLineItems();

        $orderData = [
            'status' => $this->mapOrderStatus($this->order->status),
            'currency' => $this->order->currency->code,
            'customer_id' => $customer->get(CustomFieldEnum::WOOCOMMERCE_ID->value) ?? 0,
            'line_items' => $lineItems,
            'payment_method' => $this->order->payment->payment_method ?? '',
            'payment_method_title' => $this->order->payment->payment_method_title ?? '',
            'meta_data' => [
                [
                    'key' => 'kanvas_order_id',
                    'value' => $this->order->id,
                ],
            ],
        ];

        // Add any custom metadata provided to the class
        if (! empty($this->customMetadata)) {
            $formattedMetadata = [];

            foreach ($this->customMetadata as $key => $value) {
                // Handle JSON serialization for array/object values
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                $formattedMetadata[] = [
                    'key' => $key,
                    'value' => $value,
                ];
            }

            // Merge custom metadata into the order metadata
            $orderData['meta_data'] = array_merge($orderData['meta_data'], $formattedMetadata);
        }

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
     * Get order line items and apply any custom line item metadata
     */
    protected function getLineItems(): array
    {
        $lineItems = [];

        foreach ($this->order->items as $item) {
            $itemData = [
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

            $lineItems[] = $itemData;
        }

        return $lineItems;
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
