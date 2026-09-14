<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ProcessNetSuiteSalesOrderAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'NetSuite Pull Sales Order',
    description: 'Receiver that pulls a NetSuite sales order into Kanvas, creating or updating the order and '
        . 'its products. Inbound one-way — the opposite direction to the push-order step.',
    integration: IntegrationsEnum::NETSUITE,
)]
class PullNetSuiteSalesOrderWebhookJob extends ProcessWebhookJob
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

        $successMessage = 'NetSuite Sales order Not Processed';

        // Only process if order is approved
        $mainCompany = Companies::getById($mainCompanyId);

        $processSalesOrderAction = new ProcessNetSuiteSalesOrderAction(
            $this->receiver->app,
            $mainCompany,
            $this->receiver->user
        );

        try {
            $processedProducts = $processSalesOrderAction->execute($orderId);
            $successMessage = 'NetSuite Sales Order Stock Updated';
        } catch (Exception $e) {
            report($e);
            $successMessage = 'NetSuite Sales Order Processing Failed: ' . $e->getMessage();
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
