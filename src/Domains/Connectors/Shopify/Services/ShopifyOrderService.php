<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use InvalidArgumentException;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use PHPShopify\ShopifySDK;

class ShopifyOrderService
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Warehouses $warehouses,
    ) {
        $this->shopifySdk = Client::getInstance($this->app, $this->company, $this->warehouses->regions);
    }

    /**
     * Add a comment or note to a Shopify order without overwriting existing values.
     *
     * @param array $noteAttributes Additional attributes if needed
     *
     * @return array The updated order response
     */
    public function addNoteToOrder(int|string $orderId, string $note, array $noteAttributes = []): array
    {
        $order = $this->shopifySdk->Order($orderId)->get();

        $updatedNote = trim(($order['note'] ?? '') . "\n" . $note);

        // Convert existing attributes to associative array
        $existingNoteAttributesAssoc = array_column($order['note_attributes'] ?? [], 'value', 'name');

        // Merge new attributes
        $updatedNoteAttributes = array_merge($existingNoteAttributesAssoc, $noteAttributes);

        // Convert back to indexed array
        $formattedNoteAttributes = array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($updatedNoteAttributes), $updatedNoteAttributes);

        if (strlen($updatedNote) > 5000) {
            //trim to avoid   note - is too long (maximum is 5000 characters)
            $updatedNote = substr($updatedNote, 0, 4999);
        }

        $payload = [
            'note' => $updatedNote,
            'note_attributes' => $formattedNoteAttributes,
        ];

        return $this->shopifySdk->Order($orderId)->put($payload);
    }

    /**
     * Change the fulfillment status of a Shopify order.
     *
     * @param int|string $orderId The Shopify order ID
     * @param string $status The new status (open, pending, success, cancelled, error, failure)
     * @param string $comment Optional comment for the status change
     * @param bool $notifyCustomer Whether to notify customer about status change (default: false)
     *
     * @return array The updated fulfillment response
     */
    public function changeFulfillmentStatus(
        int|string $orderId,
        string $status,
        string $comment = '',
        bool $notifyCustomer = false
    ): array {
        // Validate status
        $validStatuses = ['open', 'pending', 'success', 'cancelled', 'error', 'failure'];
        if (! in_array($status, $validStatuses)) {
            throw new InvalidArgumentException(
                'Invalid fulfillment status. Must be one of: ' . implode(', ', $validStatuses)
            );
        }

        // Get the order details first
        $order = $this->shopifySdk->Order($orderId)->get();

        // Check for existing fulfillments
        $fulfillments = $this->shopifySdk->Order($orderId)->Fulfillment()->get();

        // If no fulfillments exist, we need to create one first
        // Get the fulfillment orders (this is critical for newer Shopify API)
        $fulfillmentOrders = $this->shopifySdk->Order($orderId)->FulfillmentOrder()->get();

        if (empty($fulfillmentOrders)) {
            throw new Exception('No fulfillment orders found for this order');
        }

        // Process each fulfillment order separately
        $results = [];
        foreach ($fulfillmentOrders as $fulfillmentOrder) {
            // Get the assigned location ID
            $locationId = $fulfillmentOrder['assigned_location_id'];
            $fulfillmentOrderId = $fulfillmentOrder['id'];

            // Prepare line items for this fulfillment order
            $lineItemsByFulfillmentOrder = [];
            foreach ($fulfillmentOrder['line_items'] as $item) {
                if ($item['fulfillable_quantity'] > 0) {
                    $lineItemsByFulfillmentOrder[] = [
                        'id' => $item['id'],
                        'quantity' => $item['fulfillable_quantity'],
                    ];
                }
            }

            // Skip if no fulfillable items
            if (empty($lineItemsByFulfillmentOrder)) {
                continue;
            }

            // Create the fulfillment using the new format
            $fulfillmentData = [
                'location_id' => $locationId,
                'notify_customer' => false, // Don't notify yet, we'll notify when we update status
                'line_items_by_fulfillment_order' => [
                    [
                        'fulfillment_order_id' => $fulfillmentOrderId,
                        'fulfillment_order_line_items' => $lineItemsByFulfillmentOrder,
                    ],
                ],
            ];

            $this->shopifySdk->Fulfillment()->post($fulfillmentData);
        }

        // Return results
        return ! empty($results) ? $results[0] : [];
    }

    /**
     * Add tracking information to a Shopify order fulfillment.
     *
     * @param int|string $orderId The Shopify order ID
     * @param string $trackingNumber The tracking number to add
     * @param string $trackingCompany The shipping carrier/company (optional)
     * @param string $trackingUrl Custom tracking URL (optional)
     * @param bool $notifyCustomer Whether to notify customer about tracking (default: false)
     *
     * @return array The updated fulfillment response
     */
    public function addTrackingToOrder(
        int|string $orderId,
        string $trackingNumber,
        string $trackingCompany = '',
        string $trackingUrl = '',
        bool $notifyCustomer = false
    ): array {
        // Get the order and check for existing fulfillments
        $order = $this->shopifySdk->Order($orderId)->get();
        $fulfillmentOrders = $this->shopifySdk->Order($orderId)->FulfillmentOrder()->get();

        if (empty($fulfillmentOrders)) {
            throw new Exception('No fulfillment orders found for this order');
        }

        // Initialize tracking info
        $trackingInfo = [
            'number' => $trackingNumber,
            'company' => $trackingCompany,
            'url' => $trackingUrl,
        ];

        // Check if fulfillments exist for this order
        $existingFulfillments = $this->shopifySdk->Order($orderId)->Fulfillment()->get();

        // If fulfillments already exist, update the tracking info
        if (! empty($existingFulfillments)) {
            $fulfillmentId = $existingFulfillments[0]['id']; // Get the most recent fulfillment

            return $this->shopifySdk->Fulfillment($fulfillmentId)->update([
                'tracking_info' => $trackingInfo,
                'notify_customer' => $notifyCustomer,
            ]);
        }

        // Otherwise, create new fulfillments for each fulfillment order
        $results = [];
        foreach ($fulfillmentOrders as $fulfillmentOrder) {
            $locationId = $fulfillmentOrder['assigned_location_id'];
            $fulfillmentOrderId = $fulfillmentOrder['id'];

            // Prepare line items for this fulfillment order
            $lineItemsByFulfillmentOrder = [];
            foreach ($fulfillmentOrder['line_items'] as $item) {
                if ($item['fulfillable_quantity'] > 0) {
                    $lineItemsByFulfillmentOrder[] = [
                        'id' => $item['id'],
                        'quantity' => $item['fulfillable_quantity'],
                    ];
                }
            }

            // Skip if no fulfillable items
            if (empty($lineItemsByFulfillmentOrder)) {
                continue;
            }

            // Create the fulfillment with tracking information
            $results[] = $this->shopifySdk->Fulfillment()->post([
                'location_id' => $locationId,
                'tracking_info' => $trackingInfo,
                'notify_customer' => $notifyCustomer,
                'line_items_by_fulfillment_order' => [
                    [
                        'fulfillment_order_id' => $fulfillmentOrderId,
                        'fulfillment_order_line_items' => $lineItemsByFulfillmentOrder,
                    ],
                ],
            ]);
        }

        // Return the first result or empty array if no fulfillments were created
        return ! empty($results) ? $results[0] : [];
    }
}
