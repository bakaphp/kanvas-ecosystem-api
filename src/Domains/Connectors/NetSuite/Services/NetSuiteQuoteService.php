<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Enums\SavedSearchEnum;
use Kanvas\Souk\Orders\Models\Order;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Estimate;
use NetSuite\Classes\EstimateItem;
use NetSuite\Classes\EstimateItemList;
use NetSuite\Classes\RecordRef;
use NetSuite\NetSuiteService;

class NetSuiteQuoteService
{
    protected Client $client;
    protected NetSuiteProductSearchService $productSearchService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
        $this->productSearchService = new NetSuiteProductSearchService($app, $company);
    }

    /**
     * Create a NetSuite quote (estimate) from a Kanvas order
     */

    public function createQuoteFromOrder(Order $order, ?string $netsuiteCustomerId = null, bool $customRate = true): Estimate
    {
        $estimate = new Estimate();

        // Set basic quote information
        $estimate->tranId = 'QUOTE-' . $order->getOrderNumber();
        $orderPONumber = $order->getMetadata('poNumber') !== null ? (string) $order->getMetadata('poNumber') : null;
        $orderMemo = $orderPONumber !== null ? 'Quote created from PO#' . $orderPONumber : 'Quote created from Order NO.:' . $order->getOrderNumber();
        $estimate->memo = $order->customer_note ?? $orderMemo;
        $estimate->tranDate = date('c', strtotime($order->created_at->toDateString()));

        // Set customer reference
        if ($netsuiteCustomerId) {
            $customerRef = new RecordRef();
            $customerRef->internalId = $netsuiteCustomerId;
            $customerRef->type = 'customer';
            $estimate->entity = $customerRef;
        }

        // Set currency if available
        if ($order->currency) {
            $currencyRef = new RecordRef();
            $currencyRef->name = $order->currency;
            $estimate->currency = $currencyRef;
        }

        // Fetch all NetSuite products once and index by itemId for fast lookup
        $savedSearchId = $this->app->get(ConfigurationEnum::NET_SUITE_QUOTE_PRODUCT_SAVED_SEARCH_ID->value)
            ?? SavedSearchEnum::QUOTE_PRODUCT_SEARCH->value;

        $netsuiteProducts = $this->productSearchService->searchWithSavedSearch($savedSearchId);
        $netsuiteIndex = array_column($netsuiteProducts, null, 'itemId');

        // Create estimate items from order items
        $estimateItems = [];
        foreach ($order->items as $orderItem) {
            $estimateItem = new EstimateItem();
            $barcode = $orderItem->variant->barcode ?? $orderItem->product_sku;
            $netsuiteProduct = $netsuiteIndex[$barcode] ?? null;

            if ($netsuiteProduct === null) {
                throw new Exception("Product with barcode/SKU '{$barcode}' not found in NetSuite.");
            }

            $variantWarehouse = $orderItem->variant->variantWarehouses()->firstOrFail();
            $locationID = $variantWarehouse->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value) ?? 4;

            $itemRef = new RecordRef();
            $itemRef->name = $orderItem->product_sku;
            $itemRef->type = 'inventoryItem';
            $itemRef->internalId = $netsuiteProduct['internalId'];
            $estimateItem->item = $itemRef;

            $estimateItem->location = new RecordRef();
            $estimateItem->location->internalId = $locationID;

            if ($customRate) {
                $estimateItem->rate = $orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount;
            }

            $estimateItem->quantity = $orderItem->quantity;
            $estimateItem->amount = $orderItem->quantity * ($orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount);
            $estimateItem->description = $orderItem->product_name;

            // Set tax rate if available
            if ($orderItem->tax_rate) {
                $taxRef = new RecordRef();
                $taxRef->name = 'Tax Rate'; // You may need to map this to actual NetSuite tax codes
                $estimateItem->taxCode = $taxRef;
            }

            $estimateItems[] = $estimateItem;
        }

        // Add shipping as a line item if applicable
        if ($order->shipping_price_gross_amount && $order->shipping_price_gross_amount > 0) {
            $shippingItem = new EstimateItem();

            $shippingItemRef = new RecordRef();
            $shippingItemRef->name = 'SHIPPING'; // This should be a valid NetSuite item
            $shippingItem->item = $shippingItemRef;

            $shippingItem->quantity = 1;
            $shippingItem->rate = $order->shipping_price_gross_amount;
            $shippingItem->amount = $order->shipping_price_gross_amount;
            $shippingItem->description = $order->shipping_method_name ?? 'Shipping';

            $estimateItems[] = $shippingItem;
        }

        // Add discount as a line item if applicable
        if ($order->discount_amount && $order->discount_amount > 0) {
            $discountItem = new EstimateItem();

            $discountItemRef = new RecordRef();
            $discountItemRef->name = 'DISCOUNT'; // This should be a valid NetSuite item
            $discountItem->item = $discountItemRef;

            $discountItem->quantity = 1;
            $discountItem->rate = -$order->discount_amount; // Negative for discount
            $discountItem->amount = -$order->discount_amount;
            $discountItem->description = $order->discount_name ?? 'Discount';

            $estimateItems[] = $discountItem;
        }

        // Set the item list
        $estimateItemList = new EstimateItemList();
        $estimateItemList->item = $estimateItems;
        $estimate->itemList = $estimateItemList;

        // Create the quote in NetSuite with rate limiting
        $addRequest = new AddRequest();
        $addRequest->record = $estimate;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($addRequest) {
            $response = $service->add($addRequest);

            if ($response->writeResponse->status->isSuccess) {
                return $this->getQuoteById($response->writeResponse->baseRef->internalId);
            }

            $errorMessage = 'Error creating quote: ';
            if (isset($response->writeResponse->status->statusDetail[0]->message)) {
                $errorMessage .= $response->writeResponse->status->statusDetail[0]->message;
            }

            throw new Exception($errorMessage);
        });
    }

    /**
     * Update an existing NetSuite quote
     */
    public function updateQuote(string $quoteInternalId, Order $order): Estimate
    {
        // First, get the existing quote
        $getRequest = new \NetSuite\Classes\GetRequest();
        $estimateRef = new RecordRef();
        $estimateRef->internalId = $quoteInternalId;
        $estimateRef->type = 'estimate';
        $getRequest->baseRef = $estimateRef;

        // Update items (this is a simplified approach - you might want to handle item updates more carefully)
        $estimateItems = [];
        foreach ($order->items as $orderItem) {
            $estimateItem = new EstimateItem();

            $itemRef = new RecordRef();
            $itemRef->name = $orderItem->product_sku;
            $estimateItem->item = $itemRef;

            $estimateItem->quantity = $orderItem->quantity;
            $estimateItem->rate = $orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount;
            $estimateItem->amount = $orderItem->quantity * ($orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount);
            $estimateItem->description = $orderItem->product_name;

            $estimateItems[] = $estimateItem;
        }

        $estimateItemList = new EstimateItemList();
        $estimateItemList->item = $estimateItems;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($getRequest, $order, $estimateItemList) {
            $getResponse = $service->get($getRequest);

            if (! $getResponse->readResponse->status->isSuccess) {
                throw new Exception('Error retrieving quote for update: ' . $getResponse->readResponse->status->statusDetail[0]->message);
            }

            $estimate = $getResponse->readResponse->record;
            $estimate->memo = $order->customer_note ?? 'Quote updated from Order #' . $order->getOrderNumber();
            $estimate->itemList = $estimateItemList;

            $updateRequest = new \NetSuite\Classes\UpdateRequest();
            $updateRequest->record = $estimate;

            $response = $service->update($updateRequest);

            if ($response->writeResponse->status->isSuccess) {
                return $response->writeResponse;
            }

            throw new Exception('Error updating quote: ' . $response->writeResponse->status->statusDetail[0]->message);
        });
    }

    /**
     * Get quote by internal ID
     */
    public function getQuoteById(string|int $quoteInternalId): Estimate
    {
        $getRequest = new \NetSuite\Classes\GetRequest();
        $estimateRef = new RecordRef();
        $estimateRef->internalId = $quoteInternalId;
        $estimateRef->type = 'estimate';
        $getRequest->baseRef = $estimateRef;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($getRequest) {
            $response = $service->get($getRequest);

            if ($response->readResponse->status->isSuccess) {
                return $response->readResponse->record;
            }

            throw new Exception('Error retrieving quote: ' . $response->readResponse->status->statusDetail[0]->message);
        });
    }

    /**
     * Convert quote to sales order in NetSuite
     */
    public function convertQuoteToSalesOrder(string $quoteInternalId): \NetSuite\Classes\SalesOrder
    {
        // Get the existing quote
        $quote = $this->getQuoteById($quoteInternalId);

        // Create a new sales order from the quote
        $salesOrder = new \NetSuite\Classes\SalesOrder();

        // Copy relevant fields from quote to sales order
        $salesOrder->entity = $quote->entity;
        $salesOrder->currency = $quote->currency;
        $salesOrder->memo = $quote->memo;
        $salesOrder->itemList = $quote->itemList;

        // Set reference to original quote
        $salesOrder->createdFrom = new RecordRef();
        $salesOrder->createdFrom->internalId = $quoteInternalId;
        $salesOrder->createdFrom->type = 'estimate';

        // Create the sales order
        $addRequest = new AddRequest();
        $addRequest->record = $salesOrder;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($addRequest) {
            $response = $service->add($addRequest);

            if ($response->writeResponse->status->isSuccess) {
                return $response->writeResponse;
            }

            throw new Exception('Error converting quote to sales order: ' . $response->writeResponse->status->statusDetail[0]->message);
        });
    }
}
