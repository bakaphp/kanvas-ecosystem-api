<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteQuoteService;
use Kanvas\Souk\Orders\Models\Order;
use NetSuite\Classes\Estimate;

class PushOrderToNetSuiteQuoteAction
{
    protected NetSuiteQuoteService $quoteService;
    protected NetSuiteCustomerService $customerService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->quoteService = new NetSuiteQuoteService($app, $company);
        $this->customerService = new NetSuiteCustomerService($app, $company);
    }

    /**
     * Push a Kanvas order as a NetSuite quote
     */
    public function execute(
        Order $order,
        ?string $netsuiteCustomerId = null,
        bool $createCustomerIfNotExists = false
    ): array {
        try {
            // Validate order
            $this->validateOrder($order);

            // Handle customer creation/lookup if needed
            $customerId = $this->handleCustomer($order, $netsuiteCustomerId, $createCustomerIfNotExists);
            // Create the quote in NetSuite
            $netsuiteQuote = $this->quoteService->createQuoteFromOrder(
                $order,
                $customerId,
                $netsuiteCustomerId === null ? false : true //if customer doesnt exist we cant use custom rate for now
            );

            // Store NetSuite quote information in order custom fields and metadata
            $this->updateOrderWithNetSuiteData($order, $netsuiteQuote);

            return [
                'success' => true,
                'message' => 'Order successfully pushed to NetSuite as quote',
                'data' => [
                    'netsuite_quote_id' => $netsuiteQuote->internalId,
                    'netsuite_quote_number' => $netsuiteQuote->tranId,
                    'netsuite_customer_id' => $customerId,
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                ],
            ];
        } catch (Exception $e) {
            // Log the error (you might want to use your logging system)
            report($e);

            return [
                'success' => false,
                'message' => 'Failed to push order to NetSuite: ' . $e->getMessage(),
                'data' => [
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Update an existing NetSuite quote from a Kanvas order
     */
    public function updateQuote(Order $order): array
    {
        try {
            // Get NetSuite quote ID from custom fields first, then fallback to metadata
            $netsuiteQuoteId = $order->get(CustomFieldEnum::NET_SUITE_QUOTE_ID->value)
                ?? $order->getMetadata('netsuite_quote_id');

            if (! $netsuiteQuoteId) {
                throw new Exception('No NetSuite quote ID found in order custom fields or metadata');
            }

            // Update the quote in NetSuite
            $netsuiteQuote = $this->quoteService->updateQuote($netsuiteQuoteId, $order);

            // Update order custom fields and metadata
            $this->updateOrderWithNetSuiteData($order, $netsuiteQuote);

            return [
                'success' => true,
                'message' => 'NetSuite quote successfully updated',
                'data' => [
                    'netsuite_quote_id' => $netsuiteQuote->internalId,
                    'netsuite_quote_number' => $netsuiteQuote->tranId,
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                ],
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Failed to update NetSuite quote: ' . $e->getMessage(),
                'data' => [
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Convert the NetSuite quote to a sales order
     */
    public function convertQuoteToSalesOrder(Order $order): array
    {
        try {
            // Get NetSuite quote ID from custom fields first, then fallback to metadata
            $netsuiteQuoteId = $order->get(CustomFieldEnum::NET_SUITE_QUOTE_ID->value)
                ?? $order->getMetadata('netsuite_quote_id');

            if (! $netsuiteQuoteId) {
                throw new Exception('No NetSuite quote ID found in order custom fields or metadata');
            }

            // Convert quote to sales order
            $salesOrder = $this->quoteService->convertQuoteToSalesOrder($netsuiteQuoteId);

            // Update order metadata with sales order information
            $order->set(CustomFieldEnum::NET_SUITE_SALES_ORDER_ID->value, $salesOrder->internalId);
            $order->set(CustomFieldEnum::NET_SUITE_SALES_ORDER_NUMBER->value, $salesOrder->tranId);
            $order->set(CustomFieldEnum::NET_SUITE_CONVERTED_AT->value, now()->toISOString());

            // Also add to metadata for backward compatibility
            $order->addMetadata('netsuite_sales_order_id', $salesOrder->internalId);
            $order->addMetadata('netsuite_sales_order_number', $salesOrder->tranId);
            $order->addMetadata('netsuite_converted_at', now()->toISOString());

            return [
                'success' => true,
                'message' => 'NetSuite quote successfully converted to sales order',
                'data' => [
                    'netsuite_quote_id' => $netsuiteQuoteId,
                    'netsuite_sales_order_id' => $salesOrder->internalId,
                    'netsuite_sales_order_number' => $salesOrder->tranId,
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                ],
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Failed to convert NetSuite quote to sales order: ' . $e->getMessage(),
                'data' => [
                    'kanvas_order_id' => $order->id,
                    'kanvas_order_number' => $order->getOrderNumber(),
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Validate the order before pushing to NetSuite
     */
    protected function validateOrder(Order $order): void
    {
        if ($order->items->isEmpty()) {
            throw new Exception('Order must have at least one item');
        }

        foreach ($order->items as $item) {
            if (empty($item->product_sku)) {
                throw new Exception("Order item '{$item->product_name}' must have a SKU");
            }

            if ($item->quantity <= 0) {
                throw new Exception("Order item '{$item->product_name}' must have a positive quantity");
            }

            if (($item->unit_price_net_amount ?? 0) < 0 || ($item->unit_price_gross_amount ?? 0) < 0) {
                throw new Exception("Order item '{$item->product_name}' cannot have negative pricing");
            }
        }
    }

    /**
     * Handle customer creation/lookup
     */
    protected function handleCustomer(
        Order $order,
        ?string $netsuiteCustomerId,
        bool $syncCompanyAsCustomer
    ): ?string {
        // If NetSuite customer ID is provided, use it
        if ($netsuiteCustomerId) {
            return $netsuiteCustomerId;
        }

        if ($syncCompanyAsCustomer) {
            return (string) new SyncCompanyWithNetSuiteAction(
                $this->app,
                $order->company
            )->execute()->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);
        }

        if (! empty($order->user_email)) {
            $order->people->addEmail($order->user_email);
        }

        return (string) new SyncPeopleWithNetSuiteAction(
            $this->app,
            $order->people
        )->execute()->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);
    }

    /**
     * Update order custom fields and metadata with NetSuite information
     */
    protected function updateOrderWithNetSuiteData(Order $order, Estimate $netsuiteQuote): void
    {
        // Set custom fields
        $order->set(CustomFieldEnum::NET_SUITE_QUOTE_ID->value, $netsuiteQuote->internalId);
        $order->set(CustomFieldEnum::NET_SUITE_QUOTE_NUMBER->value, $netsuiteQuote->tranId);
        $order->set(CustomFieldEnum::NET_SUITE_PUSHED_AT->value, now()->toISOString());
        $order->set(CustomFieldEnum::NET_SUITE_QUOTE_STATUS->value, 'active');

        // Store additional NetSuite information if available
        if (isset($netsuiteQuote->entity->internalId)) {
            $order->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $netsuiteQuote->entity->internalId);
        }

        if (isset($netsuiteQuote->total)) {
            $order->set(CustomFieldEnum::NET_SUITE_QUOTE_TOTAL->value, $netsuiteQuote->total);
        }

        // Also add to metadata for backward compatibility
        $order->addMetadata('netsuite_quote_id', $netsuiteQuote->internalId);
        $order->addMetadata('netsuite_quote_number', $netsuiteQuote->tranId);
        $order->addMetadata('netsuite_pushed_at', now()->toISOString());
        $order->addMetadata('netsuite_quote_status', 'active');

        if (isset($netsuiteQuote->entity->internalId)) {
            $order->addMetadata('netsuite_customer_id', $netsuiteQuote->entity->internalId);
        }

        if (isset($netsuiteQuote->total)) {
            $order->addMetadata('netsuite_quote_total', $netsuiteQuote->total);
        }
    }

    /**
     * Sync order status from NetSuite quote
     */
    public function syncOrderStatusFromNetSuite(Order $order): array
    {
        try {
            // Get NetSuite quote ID from custom fields first, then fallback to metadata
            $netsuiteQuoteId = $order->get(CustomFieldEnum::NET_SUITE_QUOTE_ID->value)
                ?? $order->getMetadata('netsuite_quote_id');

            if (! $netsuiteQuoteId) {
                throw new Exception('No NetSuite quote ID found in order custom fields or metadata');
            }

            // Get current quote status from NetSuite
            $netsuiteQuote = $this->quoteService->getQuoteById($netsuiteQuoteId);

            // Update order custom fields and metadata with current NetSuite status
            $order->set(CustomFieldEnum::NET_SUITE_QUOTE_STATUS->value, $netsuiteQuote->status ?? 'unknown');
            $order->set(CustomFieldEnum::NET_SUITE_LAST_SYNC->value, now()->toISOString());

            // Also update metadata for backward compatibility
            $order->addMetadata('netsuite_quote_status', $netsuiteQuote->status ?? 'unknown');
            $order->addMetadata('netsuite_last_sync', now()->toISOString());

            return [
                'success' => true,
                'message' => 'Order status synced from NetSuite',
                'data' => [
                    'netsuite_quote_status' => $netsuiteQuote->status ?? 'unknown',
                    'kanvas_order_id' => $order->id,
                ],
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Failed to sync order status from NetSuite: ' . $e->getMessage(),
                'data' => [
                    'kanvas_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
}
