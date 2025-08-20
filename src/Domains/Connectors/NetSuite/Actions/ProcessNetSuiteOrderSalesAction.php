<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\Auth;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Services\NetSuiteQuoteService;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;

class ProcessNetSuiteOrderSalesAction
{
    protected NetSuiteQuoteService $quoteService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany
    ) {
        $this->quoteService = new NetSuiteQuoteService($app, $mainAppCompany);
    }

    /**
     * Process NetSuite order sales and update stock for affected products.
     */
    public function execute(string $orderId): array
    {
        $processedProducts = [];
        
        // Get the sales order from NetSuite using the quote service
        $salesOrder = $this->getSalesOrderById($orderId);
        
        if (!$salesOrder || !isset($salesOrder->itemList) || !$salesOrder->itemList->item) {
            throw new Exception("Sales order {$orderId} not found or has no items");
        }

        // Get a system user for the updates
        $user = $this->getSystemUser();
        
        // Process each item in the sales order
        $items = is_array($salesOrder->itemList->item) 
            ? $salesOrder->itemList->item 
            : [$salesOrder->itemList->item];

        foreach ($items as $item) {
            try {
                $processedProduct = $this->processOrderItem($item, $user);
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
        
        if (!$barcode) {
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
            'processed' => !isset($result['error']),
        ];
    }

    /**
     * Get a system user for performing updates.
     */
    private function getSystemUser(): Users
    {
        // Try to get current authenticated user
        $user = Auth::user();
        
        if ($user instanceof Users) {
            return $user;
        }

        // Fallback to getting any admin user from the company
        $adminUser = Users::fromCompany($this->mainAppCompany)
            ->where('roles_id', 1) // Assuming 1 is admin role
            ->first();

        if ($adminUser) {
            return $adminUser;
        }

        // Last resort: get any user from the company
        $anyUser = Users::fromCompany($this->mainAppCompany)->first();
        
        if (!$anyUser) {
            throw new Exception('No user found to perform stock updates');
        }

        return $anyUser;
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