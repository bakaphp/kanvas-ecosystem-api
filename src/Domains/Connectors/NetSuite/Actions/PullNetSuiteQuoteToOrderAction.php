<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteQuoteService;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as DataTransferObjectOrderItem;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use NetSuite\Classes\Estimate;

class PullNetSuiteQuoteToOrderAction
{
    protected NetSuiteQuoteService $quoteService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->quoteService = new NetSuiteQuoteService($app, $company);
    }

    public function execute(string|int $netsuiteQuoteId): Order
    {
        // Find order by NetSuite quote ID
        $order = $this->findOrderByNetSuiteQuoteId($netsuiteQuoteId);

        if (! $order) {
            throw new Exception("No order found with NetSuite quote ID: {$netsuiteQuoteId}");
        }

        // Fetch the quote data from NetSuite
        $netsuiteQuote = $this->quoteService->getQuoteById($netsuiteQuoteId);

        // Update order with NetSuite quote data
        $this->updateOrderFromNetSuiteQuote($order, $netsuiteQuote);

        return $order;
    }

    /**
     * Bulk update multiple orders by their NetSuite quote IDs
     */
    public function bulkExecute(array $netsuiteQuoteIds): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;
        $orders = [];

        foreach ($netsuiteQuoteIds as $quoteId) {
            $result = $this->execute($quoteId);

            if ($result['success']) {
                $successful++;
                if ($result['order']) {
                    $orders[] = $result['order'];
                }
            } else {
                $failed++;
            }

            $results[] = $result;
        }

        return [
            'success' => $failed === 0,
            'message' => "Processed {$successful} quotes successfully, {$failed} failed",
            'summary' => [
                'total' => count($netsuiteQuoteIds),
                'successful' => $successful,
                'failed' => $failed,
            ],
            'results' => $results,
            'orders' => $orders,
        ];
    }

    /**
     * Update order with data from NetSuite quote
     */
    protected function updateOrderFromNetSuiteQuote(Order $order, Estimate $netsuiteQuote): void
    {
        // Update basic order information
        if (isset($netsuiteQuote->memo) && $netsuiteQuote->memo !== $order->customer_note) {
            $order->customer_note = $netsuiteQuote->memo;
        }

        // Update order status based on quote status
        if (isset($netsuiteQuote->status)) {
            $this->updateOrderStatus($order, $netsuiteQuote->status);
        }

        // Update totals if they differ significantly
        if (isset($netsuiteQuote->total)) {
            $netsuiteTotal = (float) $netsuiteQuote->total;
            $currentTotal = $order->total_gross_amount;

            // Only update if there's a significant difference (more than 0.01)
            if (abs($netsuiteTotal - $currentTotal) > 0.01) {
                $order->total_gross_amount = $netsuiteTotal;
                $order->total_net_amount = $netsuiteTotal; // Adjust based on your tax logic
            }
        }

        // Update line items if they exist and differ
        if (isset($netsuiteQuote->itemList) && isset($netsuiteQuote->itemList->item)) {
            $this->updateOrderItems($order, $netsuiteQuote->itemList->item);
        }

        // Update custom fields with NetSuite data
        $order->set(CustomFieldEnum::NET_SUITE_QUOTE_STATUS->value, $netsuiteQuote->status ?? 'unknown');
        $order->set(CustomFieldEnum::NET_SUITE_QUOTE_TOTAL->value, $netsuiteQuote->total ?? 0);
        $order->set(CustomFieldEnum::NET_SUITE_LAST_SYNC->value, now()->toISOString());

        // Update customer information if available
        if (isset($netsuiteQuote->entity->internalId)) {
            $order->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $netsuiteQuote->entity->internalId);
        }

        // Also update metadata for backward compatibility
        $order->addMetadata('netsuite_quote_status', $netsuiteQuote->status ?? 'unknown');
        $order->addMetadata('netsuite_quote_total', $netsuiteQuote->total ?? 0);
        $order->addMetadata('netsuite_last_sync', now()->toISOString());
        $order->addMetadata('netsuite_pulled_at', now()->toISOString());

        if (isset($netsuiteQuote->entity->internalId)) {
            $order->addMetadata('netsuite_customer_id', $netsuiteQuote->entity->internalId);
        }

        // Save the order
        $order->saveOrFail();
    }

    /**
     * Update order status based on NetSuite quote status
     */
    protected function updateOrderStatus(Order $order, string $netsuiteStatus): void
    {
        // Map NetSuite quote statuses to your order statuses
        $statusMapping = [
            'Open' => 'pending',
            'Pending Approval' => 'pending',
            'Approved' => 'confirmed',
            'Rejected' => 'cancelled',
            'Closed' => 'completed',
            'Converted' => 'completed',
        ];

        $newStatus = $statusMapping[$netsuiteStatus] ?? null;

        if ($newStatus && $order->fulfillment_status !== $newStatus) {
            $order->fulfillment_status = $newStatus;
        }
    }

    /**
     * Update order items based on NetSuite quote items
     */
    protected function updateOrderItems(Order $order, array $netsuiteItems): void
    {
        // Track which items we've processed
        $processedSkus = [];

        foreach ($netsuiteItems as $netsuiteItem) {
            if (! isset($netsuiteItem->item) || ! isset($netsuiteItem->item->name)) {
                continue;
            }

            $sku = $netsuiteItem->item->name;
            $quantity = $netsuiteItem->quantity ?? 0;
            $rate = $netsuiteItem->rate ?? 0;
            $description = $netsuiteItem->description ?? $sku;

            $processedSkus[] = $sku;

            // Find corresponding order item by SKU
            $orderItem = $order->items->where('product_sku', $sku)->first();

            if ($orderItem) {
                // Update existing order item
                $this->updateExistingOrderItem($orderItem, (float) $quantity, (float) $rate, $description);
            } else {
                // Try to add new order item (only if variant exists)
                $this->addNewOrderItem($order, $sku, (float) $quantity, (float) $rate, $description);
            }
        }

        // Recalculate order totals after item updates
        $order->calculateTotals();
    }

    /**
     * Update an existing order item
     */
    protected function updateExistingOrderItem(OrderItem $orderItem, float $quantity, float $rate, string $description): void
    {
        $updated = false;

        // Update quantity if different
        if ($orderItem->quantity != $quantity) {
            $orderItem->quantity = $quantity;
            $updated = true;
        }

        // Update price if different (with tolerance for floating point comparison)
        $currentPrice = $orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount ?? 0;
        if (abs($currentPrice - $rate) > 0.01) {
            $orderItem->unit_price_gross_amount = $rate;
            $orderItem->unit_price_net_amount = $rate; // Adjust based on your tax logic
            $updated = true;
        }

        // Update description if different
        if ($orderItem->product_name !== $description) {
            $orderItem->product_name = $description;
            $updated = true;
        }

        // Save the order item if updated (remove the total calculations)
        if ($updated) {
            $orderItem->saveOrFail();
        }
    }

    /**
     * Add a new order item from NetSuite quote (only if variant exists)
     */
    protected function addNewOrderItem(
        Order $order,
        string $sku,
        float $quantity,
        float $rate,
        string $description
    ): void {
        // Try to find the variant/product in the system
        $variant = $this->findVariantBySku($sku);

        if (! $variant) {
            // Log that we couldn't find the product but don't create the item
            report(new Exception("Product with SKU '{$sku}' not found in Kanvas system when adding from NetSuite quote. Skipping item."));

            return;
        }

        // Get the currency model
        $currency = $this->getCurrencyModel($order->currency ?? 'USD');

        // Create OrderItemDto
        $orderItemDto = new DataTransferObjectOrderItem(
            app: $this->app,
            variant: $variant,
            name: $variant->name,
            sku: $sku,
            quantity: $quantity,
            price: $rate,
            discount: 0, // Adjust based on your logic
            tax: 0, // Adjust based on your logic
            currency: $currency,
            metadata: ['source' => 'netsuite_quote']
        );

        // Use the existing addItem method
        $order->addItem($orderItemDto);
    }

    /**
     * Find variant by SKU in the system
     */
    protected function findVariantBySku(string $sku): ?Variants
    {
        // Try to find by SKU first
        $variant = Variants::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->first();

        if (! $variant) {
            // Try to find by barcode as fallback
            $variant = Variants::fromApp($this->app)
                ->fromCompany($this->company)
                ->where('barcode', $sku)
                ->first();
        }

        return $variant;
    }

    /**
     * Get currency model
     */
    protected function getCurrencyModel(string $currencyCode): Currencies
    {
        // Get the currency from the database
        $currency = Currencies::where('code', $currencyCode)->first();

        // If not found, create a default one or use a fallback
        if (! $currency) {
            $currency = Currencies::getBaseCurrency();
        }

        return $currency;
    }

    /**
     * Find order by NetSuite quote ID
     */
    protected function findOrderByNetSuiteQuoteId(string|int $netsuiteQuoteId): ?Order
    {
        // Convert to string for consistent searching
        $quoteIdString = (string) $netsuiteQuoteId;
        $quoteIdInt = (int) $netsuiteQuoteId;

        // First try to find by custom field
        $orderByCustomField = Order::getByCustomField(
            CustomFieldEnum::NET_SUITE_QUOTE_ID->value,
            $quoteIdString,
            $this->company
        );

        if ($orderByCustomField) {
            return $orderByCustomField;
        }

        // Fallback to metadata search with proper null/empty checks
        return Order::fromApp($this->app)
            ->fromCompany($this->company)
            ->whereNotNull('metadata')
            ->where('metadata', '!=', '{}')
            ->where('metadata', '!=', '[]')
            ->where(function ($query) use ($quoteIdString, $quoteIdInt) {
                $query->whereRaw("JSON_EXTRACT(metadata, '$.netsuite_quote_id') = ?", [$quoteIdString])
                      ->orWhereRaw("JSON_EXTRACT(metadata, '$.netsuite_quote_id') = ?", [$quoteIdInt]);
            })
            ->first();
    }

    /**
     * Get list of fields that were updated
     */
    protected function getUpdatedFields(Order $order, Estimate $netsuiteQuote): array
    {
        $updatedFields = [];

        if (isset($netsuiteQuote->memo) && $netsuiteQuote->memo !== $order->customer_note) {
            $updatedFields['customer_note'] = $netsuiteQuote->memo;
        }

        if (isset($netsuiteQuote->status)) {
            $updatedFields['quote_status'] = $netsuiteQuote->status;
        }

        if (isset($netsuiteQuote->total)) {
            $netsuiteTotal = (float) $netsuiteQuote->total;
            $currentTotal = $order->total_gross_amount;

            if (abs($netsuiteTotal - $currentTotal) > 0.01) {
                $updatedFields['total'] = $netsuiteTotal;
            }
        }

        return $updatedFields;
    }

    /**
     * Helper method to check if an order exists for a given NetSuite quote ID
     */
    public function orderExistsForQuote(string $netsuiteQuoteId): bool
    {
        return $this->findOrderByNetSuiteQuoteId($netsuiteQuoteId) !== null;
    }

    /**
     * Get the order for a given NetSuite quote ID without updating it
     */
    public function getOrderByQuoteId(string $netsuiteQuoteId): ?Order
    {
        return $this->findOrderByNetSuiteQuoteId($netsuiteQuoteId);
    }
}
