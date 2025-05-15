<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
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
        // Get the order and its fulfillments
        $order = $this->shopifySdk->Order($orderId)->get();
        $fulfillments = $this->shopifySdk->Order($orderId)->Fulfillment()->get();

        // If no fulfillments exist yet, create one first
        if (empty($fulfillments)) {
            return $this->createFulfillmentWithTracking(
                $orderId,
                $trackingNumber,
                $trackingCompany,
                $trackingUrl,
                $notifyCustomer
            );
        }

        // Otherwise, update the most recent fulfillment
        $fulfillmentId = $fulfillments[0]['id']; // Latest fulfillment

        $payload = [
            'tracking_number' => $trackingNumber,
            'notify_customer' => $notifyCustomer,
        ];

        if (! empty($trackingCompany)) {
            $payload['tracking_company'] = $trackingCompany;
        }

        if (! empty($trackingUrl)) {
            $payload['tracking_url'] = $trackingUrl;
        }

        return $this->shopifySdk->Order($orderId)->Fulfillment($fulfillmentId)->update($payload);
    }

    /**
     * Create a new fulfillment with tracking for an order.
     * This is a helper method used by addTrackingToOrder when no fulfillments exist.
     *
     * @param int|string $orderId The Shopify order ID
     * @param string $trackingNumber The tracking number to add
     * @param string $trackingCompany The shipping carrier/company
     * @param string $trackingUrl Custom tracking URL
     * @param bool $notifyCustomer Whether to notify customer
     *
     * @return array The created fulfillment response
     */
    private function createFulfillmentWithTracking(
        int|string $orderId,
        string $trackingNumber,
        string $trackingCompany = '',
        string $trackingUrl = '',
        bool $notifyCustomer = false
    ): array {
        $order = $this->shopifySdk->Order($orderId)->get();

        // Get line items from order
        $lineItems = [];
        foreach ($order['line_items'] as $item) {
            $lineItems[] = [
                'id' => $item['id'],
                'quantity' => $item['quantity'],
            ];
        }

        $payload = [
            'line_items' => $lineItems,
            'tracking_number' => $trackingNumber,
            'notify_customer' => $notifyCustomer,
        ];

        if (! empty($trackingCompany)) {
            $payload['tracking_company'] = $trackingCompany;
        }

        if (! empty($trackingUrl)) {
            $payload['tracking_url'] = $trackingUrl;
        }

        return $this->shopifySdk->Order($orderId)->Fulfillment()->post($payload);
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

        // Get existing fulfillments
        $fulfillments = $this->shopifySdk->Order($orderId)->Fulfillment()->get();

        // If no fulfillments exist, we need to create one first
        if (empty($fulfillments)) {
            // Create initial fulfillment (this creates with 'pending' status by default)
            $order = $this->shopifySdk->Order($orderId)->get();

            // Get line items from order
            $lineItems = [];
            foreach ($order['line_items'] as $item) {
                $lineItems[] = [
                    'id' => $item['id'],
                    'quantity' => $item['quantity'],
                ];
            }

            $fulfillment = $this->shopifySdk->Order($orderId)->Fulfillment()->post([
                'line_items' => $lineItems,
                'notify_customer' => false, // Don't notify yet
            ]);

            $fulfillmentId = $fulfillment['id'];
        } else {
            // Use the most recent fulfillment
            $fulfillmentId = $fulfillments[0]['id'];
        }

        // Now change the status of the fulfillment
        // For status changes we need to call specific endpoints based on the status
        switch ($status) {
            case 'open':
                return $this->shopifySdk->Order($orderId)->Fulfillment($fulfillmentId)->open([
                    'notify_customer' => $notifyCustomer,
                    'comment' => $comment,
                ]);
            case 'cancelled':
                return $this->shopifySdk->Order($orderId)->Fulfillment($fulfillmentId)->cancel([
                    'notify_customer' => $notifyCustomer,
                    'comment' => $comment,
                ]);
            case 'success':
                return $this->shopifySdk->Order($orderId)->Fulfillment($fulfillmentId)->complete([
                    'notify_customer' => $notifyCustomer,
                    'comment' => $comment,
                ]);
            default:
                // For other statuses (pending, error, failure), use regular update
                return $this->shopifySdk->Order($orderId)->Fulfillment($fulfillmentId)->update([
                    'status' => $status,
                    'notify_customer' => $notifyCustomer,
                    'comment' => $comment,
                ]);
        }
    }
}
