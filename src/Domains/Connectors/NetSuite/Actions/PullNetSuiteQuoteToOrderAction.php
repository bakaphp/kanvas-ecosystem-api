<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteQuoteService;
use Kanvas\Souk\Orders\Models\Order;
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
        // This is a basic implementation - you may want to make this more sophisticated
        // based on your business logic for handling item updates

        foreach ($netsuiteItems as $netsuiteItem) {
            if (! isset($netsuiteItem->item) || ! isset($netsuiteItem->item->name)) {
                continue;
            }

            $sku = $netsuiteItem->item->name;
            $quantity = $netsuiteItem->quantity ?? 0;
            $rate = $netsuiteItem->rate ?? 0;

            // Find corresponding order item by SKU
            $orderItem = $order->items->where('product_sku', $sku)->first();

            if ($orderItem) {
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

                if ($updated) {
                    $orderItem->saveOrFail();
                }
            }
        }

        // Recalculate order totals after item updates
        $order->calculateTotals();
    }

    /**
     * Find order by NetSuite quote ID
     */
    protected function findOrderByNetSuiteQuoteId(string|int $netsuiteQuoteId): ?Order
    {
        // First try to find by custom field
        $orderByCustomField = Order::fromApp($this->app)
            ->fromCompany($this->company)
            ->whereHas('customFields', function ($query) use ($netsuiteQuoteId) {
                $query->where('name', CustomFieldEnum::NET_SUITE_QUOTE_ID->value)
                      ->where('value', $netsuiteQuoteId);
            })
            ->first();

        if ($orderByCustomField) {
            return $orderByCustomField;
        }

        // Fallback to metadata search
        return Order::fromApp($this->app)
            ->fromCompany($this->company)
            ->whereJsonContains('metadata->netsuite_quote_id', $netsuiteQuoteId)
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
