<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Services\NetSuiteQuoteService;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;

class ProcessNetSuiteSalesOrderAction
{
    protected NetSuiteQuoteService $quoteService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->quoteService = new NetSuiteQuoteService($app, $mainAppCompany);
    }

    /**
     * Process NetSuite order sales and update stock for affected products.
     */
    public function execute(string|int $orderId, ?int $limit = null): array
    {
        $processedProducts = [];

        // Get the sales order from NetSuite using the quote service
        $salesOrder = $this->getSalesOrderById($orderId);

        if (! $salesOrder || ! isset($salesOrder->itemList) || ! $salesOrder->itemList->item) {
            throw new Exception("Sales order {$orderId} not found or has no items");
        }

        // Process each item in the sales order
        $items = is_array($salesOrder->itemList->item)
            ? $salesOrder->itemList->item
            : [$salesOrder->itemList->item];

        // Apply limit if specified
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            try {
                $processedProduct = $this->processOrderItem($item, $this->user);
                if ($processedProduct) {
                    $processedProducts[] = $processedProduct;
                }
            } catch (Exception $e) {
                // Log error but continue processing other items
                report($e);
                $processedProducts[] = [
                    'item_id' => $item->item->internalId ?? 'unknown',
                    'barcode' => $item->item->name ?? 'unknown',
                    'error' => $e->getMessage(),
                    'processed' => false,
                ];
            }
        }

        return $processedProducts;
    }

    /**
     * Process individual order item and update stock.
     */
    private function processOrderItem($item, Users $user): ?array
    {
        // Get the item's barcode/item number
        $barcode = $item->item->name ?? null;

        if (! $barcode) {
            return [
                'item_id' => $item->item->internalId ?? 'unknown',
                'barcode' => null,
                'error' => 'No barcode found for item',
                'processed' => false,
            ];
        }
        // Use PullNetSuiteProductPriceAction to update the stock
        $pullProductAction = new PullNetSuiteProductPriceAction(
            $this->app,
            $this->mainAppCompany,
            $user
        );

        $result = $pullProductAction->execute($barcode);

        return [
            'item_id' => $item->item->internalId ?? 'unknown',
            'barcode' => $barcode,
            'quantity' => $item->quantity ?? 0,
            'result' => $result,
            'processed' => ! isset($result['error']),
        ];
    }

    /**
     * Get sales order by internal ID using the NetSuite service
     */
    private function getSalesOrderById(string|int $salesOrderInternalId): \NetSuite\Classes\SalesOrder
    {
        $getRequest = new GetRequest();
        $salesOrderRef = new RecordRef();
        $salesOrderRef->internalId = $salesOrderInternalId;
        $salesOrderRef->type = 'salesOrder';
        $getRequest->baseRef = $salesOrderRef;

        $service = (new Client($this->app, $this->mainAppCompany))->getService();
        $response = $service->get($getRequest);

        if ($response->readResponse->status->isSuccess) {
            return $response->readResponse->record;
        } else {
            throw new Exception('Error retrieving sales order: ' . $response->readResponse->status->statusDetail[0]->message);
        }
    }
}
