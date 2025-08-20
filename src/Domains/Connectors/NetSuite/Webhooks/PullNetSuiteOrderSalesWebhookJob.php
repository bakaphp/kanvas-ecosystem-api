<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ProcessNetSuiteOrderSalesAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullNetSuiteOrderSalesWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $orderId = $this->webhookRequest->payload['id'];
        $orderTranId = $this->webhookRequest->payload['tranId'] ?? null;
        $orderStatus = $this->webhookRequest->payload['status'] ?? null;
        $orderTotal = $this->webhookRequest->payload['total'] ?? null;
        $orderMemo = $this->webhookRequest->payload['memo'] ?? null;
        $entityId = $this->webhookRequest->payload['entity'] ?? null;

        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');
        $processedProducts = [];

        $successMessage = 'NetSuite OrderSales Not Processed';
        
        // Only process if order is approved
        if ($orderStatus === 'Approved' && $mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);

            $processOrderSalesAction = new ProcessNetSuiteOrderSalesAction(
                $this->receiver->app,
                $mainCompany
            );

            try {
                $processedProducts = $processOrderSalesAction->execute($orderId);
                $successMessage = 'NetSuite OrderSales Stock Updated';
            } catch (Exception $e) {
                report($e);
                $successMessage = 'NetSuite OrderSales Processing Failed: ' . $e->getMessage();
            }
        } elseif ($orderStatus !== 'Approved') {
            $successMessage = 'NetSuite OrderSales Not Approved - Skipped';
        }

        return [
            'message' => $successMessage,
            'netsuite_order_id' => $orderId,
            'netsuite_order_number' => $orderTranId,
            'netsuite_order_status' => $orderStatus,
            'netsuite_order_total' => $orderTotal,
            'netsuite_entity_id' => $entityId,
            'mainCompanyId' => $mainCompanyId,
            'processed_products_count' => count($processedProducts),
            'processed_products' => $processedProducts,
        ];
    }
}