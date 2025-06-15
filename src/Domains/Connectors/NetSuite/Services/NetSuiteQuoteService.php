<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Souk\Orders\Models\Order;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Estimate;
use NetSuite\Classes\EstimateItem;
use NetSuite\Classes\EstimateItemList;
use NetSuite\Classes\RecordRef;
use NetSuite\NetSuiteService;

class NetSuiteQuoteService
{
    protected NetSuiteService $service;
    protected NetSuiteProductService $productService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = (new Client($app, $company))->getService();
        $this->productService = new NetSuiteProductService($app, $company);
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
        $orderMemo = $orderPONumber !== null ? 'Quote created from PO#' . $orderPONumber : 'Quote created from Order #' . $order->getOrderNumber();
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

        // Create estimate items from order items
        $estimateItems = [];
        foreach ($order->items as $orderItem) {
            $estimateItem = new EstimateItem();
            $searchNetsuiteProductInfo = $this->productService->searchProductByItemNumber($orderItem->variant->barcode ?? $orderItem->product_sku);

            // Set item reference (you may need to map SKU to NetSuite item internal ID)
            $itemRef = new RecordRef();
            $itemRef->name = $orderItem->product_sku;
            $itemRef->type = 'inventoryItem';
            $itemRef->internalId = $searchNetsuiteProductInfo[0]->internalId;
            $estimateItem->item = $itemRef;

            $estimateItem->quantity = $orderItem->quantity;
            if ($customRate) {
                $estimateItem->rate = $orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount;
            }
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

        // Create the quote in NetSuite
        $addRequest = new AddRequest();
        $addRequest->record = $estimate;

        $response = $this->service->add($addRequest);

        if ($response->writeResponse->status->isSuccess) {
            return $this->getQuoteById($response->writeResponse->baseRef->internalId);
        } else {
            $errorMessage = 'Error creating quote: ';
            if (isset($response->writeResponse->status->statusDetail[0]->message)) {
                $errorMessage .= $response->writeResponse->status->statusDetail[0]->message;
            }

            throw new Exception($errorMessage);
        }
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


        $getResponse = $this->service->get($getRequest);

        if (! $getResponse->readResponse->status->isSuccess) {
            throw new Exception('Error retrieving quote for update: ' . $getResponse->readResponse->status->statusDetail[0]->message);
        }

        $estimate = $getResponse->readResponse->record;

        // Update quote fields
        $estimate->memo = $order->customer_note ?? 'Quote updated from Order #' . $order->getOrderNumber();

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
        $estimate->itemList = $estimateItemList;

        // Update the quote in NetSuite
        $updateRequest = new \NetSuite\Classes\UpdateRequest();
        $updateRequest->record = $estimate;

        $response = $this->service->update($updateRequest);

        if ($response->writeResponse->status->isSuccess) {
            return $response->writeResponse;
        } else {
            throw new Exception('Error updating quote: ' . $response->writeResponse->status->statusDetail[0]->message);
        }
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

        $response = $this->service->get($getRequest);

        if ($response->readResponse->status->isSuccess) {
            return $response->readResponse->record;
        } else {
            throw new Exception('Error retrieving quote: ' . $response->readResponse->status->statusDetail[0]->message);
        }
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

        $response = $this->service->add($addRequest);

        if ($response->writeResponse->status->isSuccess) {
            return $response->writeResponse;
        } else {
            throw new Exception('Error converting quote to sales order: ' . $response->writeResponse->status->statusDetail[0]->message);
        }
    }
}
