<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Automattic\WooCommerce\Client as WooCommerceClient;
use Exception;
use Kanvas\Connectors\WooCommerce\Client;
use Kanvas\Connectors\WooCommerce\Enums\CustomFieldEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceCustomerService;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceProductService;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class PushOrderToWooCommerceAction
{
    protected WooCommerceClient $wooCommerceService;

    public function __construct(
        protected Order $order,
        protected array $customMetadata = [],
        protected array $lineItemsMetaData = [],
    ) {
        $this->wooCommerceService = (new Client($this->order->app))->getClient();

        $this->customMetadata = $customMetadata;
        $this->lineItemsMetaData = $lineItemsMetaData;
    }

    public function execute(): object
    {
        if ($this->order->get(CustomFieldEnum::WOOCOMMERCE_ID->value) !== null) {
            throw new Exception('Order already pushed to WooCommerce');
        }

        // Format the order data for WooCommerce (including all eSIM data)
        $orderData = $this->formatOrderData();

        // Send the order to WooCommerce with all data in a single request
        $woocommerceOrder = $this->wooCommerceService->post('orders', $orderData);

        // Mark the order as paid to ensure payment_complete hook is triggered
        if ($woocommerceOrder && isset($woocommerceOrder->id)) {
            $this->completePayment($woocommerceOrder->id);
        }

        $this->order->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $woocommerceOrder->id);

        return $woocommerceOrder;
    }

    protected function completePayment(int $orderId): void
    {
        // First update to processing status
        $this->wooCommerceService->put("orders/{$orderId}", ['status' => 'processing']);

        // Add transaction ID and payment date
        $now = gmdate('Y-m-d H:i:s');
        $meta = [
            'meta_data' => [
                ['key' => '_transaction_id', 'value' => 'kanvas_'.time()],
                ['key' => '_paid_date', 'value' => $now],
            ],
        ];
        $this->wooCommerceService->put("orders/{$orderId}", $meta);

        // Update to completed status
        $this->wooCommerceService->put("orders/{$orderId}", ['status' => 'completed']);
    }

    /**
     * Format the order data for WooCommerce.
     */
    protected function formatOrderData(): array
    {
        // Get customer information
        $customer = $this->order->people;

        // Get line items
        $lineItems = $this->getLineItems();

        // Check if customer exists in WooCommerce by email
        $customerId = $this->getCustomerIdByEmail($this->order->user_email);

        // If no customer ID from custom field, use the one we found by email
        $customerWooId = $customer->get(CustomFieldEnum::WOOCOMMERCE_ID->value) ?? $customerId;

        $orderData = [
            'status'               => 'completed', // Start with pending to ensure proper hook sequence
            'currency'             => $this->order->currency,
            'customer_id'          => $customerWooId,
            'line_items'           => $lineItems,
            'payment_method'       => $this->order->payment->payment_method ?? 'kanvas',
            'payment_method_title' => $this->order->payment->payment_method_title ?? 'Kanvas Integration',
            'meta_data'            => [
                [
                    'key'   => 'kanvas_order_id',
                    'value' => $this->order->id,
                ],
                [
                    'key'   => 'order_source',
                    'value' => 'kanvas',
                ],
                [
                    'key'   => 'purchase_type',
                    'value' => 'new',
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
                    'key'   => $key,
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

    protected function getCustomerIdByEmail(string $email): int
    {
        $customer = new WooCommerceCustomerService($this->order->app);
        $customerId = $customer->getCustomerIdByEmail($email);

        if ($customerId) {
            return $customerId;
        }

        // If no customer found with this email, create one
        if ($this->order->people) {
            return $customer->createCustomer($this->order->people, $email);
        }

        return 0;
    }

    protected function formatAddress(Address $address, People $customer): array
    {
        $country = $address->country;

        return [
            'first_name' => $customer->firstname,
            'last_name'  => $customer->lastname,
            'address_1'  => $address->address,
            'address_2'  => $address->address_2 ?? '',
            'city'       => $address->city,
            'state'      => $address->state,
            'postcode'   => $address->zip,
            'country'    => $country->code ?? '',
            'email'      => $this->order->user_email,
            'phone'      => $this->order->user_phone ?? '',
        ];
    }

    /**
     * Get order line items and apply any custom line item metadata.
     */
    protected function getLineItems(): array
    {
        $lineItems = [];

        $wooProduct = new WooCommerceProductService($this->order->app);
        foreach ($this->order->items as $item) {
            // Get product data (ID and name) from SKU
            $productData = $wooProduct->getProductDataBySku($item->product_sku);

            if ($productData['id'] === 0) {
                throw new Exception('Product not found for SKU: '.$item->product_sku.' please sync products first');
            }

            $itemData = [
                'product_id' => $productData['id'],
                'name'       => $productData['name'],
                'quantity'   => $item->quantity,
                'price'      => $item->unit_price_net_amount,
                'total'      => (string) ((float) $item->quantity * (float) $item->unit_price_net_amount),
                //'sku' => $item->product_sku ?? '',
                'meta_data' => $this->lineItemsMetaData,
            ];

            $lineItems[] = $itemData;
        }

        return $lineItems;
    }

    /**
     * Map Kanvas order status to WooCommerce order status.
     */
    protected function mapOrderStatus(string $status): string
    {
        $statusMap = [
            'pending'    => 'pending',
            'processing' => 'processing',
            'completed'  => 'completed',
            'cancelled'  => 'cancelled',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
            'on-hold'    => 'on-hold',
            // Add other status mappings as needed
        ];

        return $statusMap[$status] ?? 'pending';
    }
}
