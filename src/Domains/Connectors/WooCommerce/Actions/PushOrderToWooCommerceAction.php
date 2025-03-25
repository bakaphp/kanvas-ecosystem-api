<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Automattic\WooCommerce\Client as WooCommerceClient;
use Baka\Support\Str;
use Exception;
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
        protected array $lineItemsMetaData = [],
    ) {
        $this->wooCommerceService = (new Client($this->order->app))->getClient();

        $this->customMetadata = $customMetadata;
        $this->lineItemsMetaData = $lineItemsMetaData;
    }

    public function execute(): object
    {
        // Format the order data for WooCommerce (including all eSIM data)
        $orderData = $this->formatOrderData();

        // Send the order to WooCommerce with all data in a single request
        $woocommerceOrder = $this->wooCommerceService->post('orders', $orderData);

        // Mark the order as paid to ensure payment_complete hook is triggered
        if ($woocommerceOrder && isset($woocommerceOrder->id)) {
            $this->completePayment($woocommerceOrder->id);
        }

        return $woocommerceOrder;
    }

    /**
     * Properly complete the payment for the order using multiple steps
     * This ensures all necessary WooCommerce hooks are triggered
     */
    protected function completePayment(int $orderId): void
    {
        
            // First update to processing status
            $this->wooCommerceService->put("orders/{$orderId}", ['status' => 'processing']);
            
            // Add transaction ID and payment date
            $now = gmdate('Y-m-d H:i:s');
            $meta = [
                'meta_data' => [
                    ['key' => '_transaction_id', 'value' => 'kanvas_' . time()],
                    ['key' => '_paid_date', 'value' => $now]
                ]
            ];
            $this->wooCommerceService->put("orders/{$orderId}", $meta);
            
            // Update to completed status
            $this->wooCommerceService->put("orders/{$orderId}", ['status' => 'completed']);
       
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

        // Check if customer exists in WooCommerce by email
        $customerId = $this->getCustomerIdByEmail($this->order->user_email);

        // If no customer ID from custom field, use the one we found by email
        $customerWooId = $customer->get(CustomFieldEnum::WOOCOMMERCE_ID->value) ?? $customerId;

        $orderData = [
            'status' => 'completed', // Start with pending to ensure proper hook sequence
            'currency' => $this->order->currency,
            'customer_id' => $customerWooId,
            'line_items' => $lineItems,
            'payment_method' => $this->order->payment->payment_method ?? 'kanvas',
            'payment_method_title' => $this->order->payment->payment_method_title ?? 'Kanvas Integration',
            'meta_data' => [
                [
                    'key' => 'kanvas_order_id',
                    'value' => $this->order->id,
                ],
                [
                    'key' => 'order_source',
                    'value' => 'kanvas',
                ],
                [
                    'key' => 'purchase_type',
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

    /**
     * Find a WooCommerce customer by email
     *
     * @param string $email The customer email to search for
     * @return int The customer ID or 0 if not found
     */
    protected function getCustomerIdByEmail(string $email): int
    {
        if (empty($email)) {
            return 0;
        }

        // Search for customers with the given email
        $customers = $this->wooCommerceService->get('customers', [
            'email' => $email,
            'per_page' => 1, // Limit to 1 result for efficiency
        ]);

        // If we found a matching customer, return their ID
        if (! empty($customers) && is_array($customers)) {
            return (int) $customers[0]->id;
        }

        // If no customer found with this email, create one
        if ($this->order->people) {
            return $this->createCustomer($this->order->people, $email);
        }

        return 0;
    }

    /**
     * Create a new customer in WooCommerce
     *
     * @param People $people Kanvas people object
     * @param string $email Customer email
     * @return int The newly created customer ID or 0 if creation failed
     */
    protected function createCustomer(People $people, string $email): int
    {
        $customerData = [
            'email' => $email,
            'first_name' => $people->firstname,
            'last_name' => $people->lastname,
            'username' => $email, // Using email as username
            'password' => Str::random(12), // Generate a random password
        ];

        // Optionally add address information if available
        if ($this->order->billing_address_id !== null) {
            $billingAddress = $this->order->billingAddress;
            $customerData['billing'] = [
                'first_name' => $people->firstname,
                'last_name' => $people->lastname,
                'address_1' => $billingAddress->address,
                'address_2' => $billingAddress->address_2 ?? '',
                'city' => $billingAddress->city,
                'state' => $billingAddress->state,
                'postcode' => $billingAddress->zip,
                'country' => $billingAddress->country ? $billingAddress->country->code : '',
                'email' => $email,
                'phone' => $this->order->user_phone ?? '',
            ];
        }

        if ($this->order->shipping_address_id !== null) {
            $shippingAddress = $this->order->shippingAddress;
            $customerData['shipping'] = [
                'first_name' => $people->firstname,
                'last_name' => $people->lastname,
                'address_1' => $shippingAddress->address,
                'address_2' => $shippingAddress->address_2 ?? '',
                'city' => $shippingAddress->city,
                'state' => $shippingAddress->state,
                'postcode' => $shippingAddress->zip,
                'country' => $shippingAddress->country ? $shippingAddress->country->code : '',
            ];
        }

        try {
            $response = $this->wooCommerceService->post('customers', $customerData);

            // Store the WooCommerce customer ID in the Kanvas people record
            if (isset($response->id)) {
                $people->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $response->id);
                return (int) $response->id;
            }
        } catch (Exception $e) {
            // Handle the error as needed
        }

        return 0;
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
            // Get product data (ID and name) from SKU
            $productData = $this->getProductDataBySku('esim-eu'); //$item->product_sku ?? '');

            $itemData = [
                'product_id' => $productData['id'],
                'name' => $productData['name'],
                'quantity' => $item->quantity,
                'price' => $item->unit_price_net_amount,
                'total' => (string)((float) $item->quantity * (float) $item->unit_price_net_amount),
                //'sku' => $item->product_sku ?? '',
                'meta_data' => $this->lineItemsMetaData,
            ];

            $lineItems[] = $itemData;
        }

        return $lineItems;
    }

    /**
     * Get WooCommerce product data (ID and name) by SKU
     */
    protected function getProductDataBySku(string $sku): array
    {
        // Default values if product not found
        $productData = [
            'id' => 0,
            'name' => 'Product ' . $sku,
        ];

        if (empty($sku)) {
            return $productData;
        }

        // Search for products with the given SKU
        $products = $this->wooCommerceService->get('products', [
            'sku' => $sku,
            'per_page' => 1, // Limit to 1 result for efficiency
        ]);

        // If we found a matching product, return its ID and name
        if (! empty($products) && is_array($products)) {
            $productData['id'] = (int) $products[0]->id;
            $productData['name'] = $products[0]->name ?? ('Product ' . $sku);
        }

        return $productData;
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