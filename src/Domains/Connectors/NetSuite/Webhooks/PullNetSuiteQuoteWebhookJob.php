<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteQuoteToOrderAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullNetSuiteQuoteWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $quoteId = $this->webhookRequest->payload['id'];
        $quoteTranId = $this->webhookRequest->payload['tranId'] ?? null;
        $quoteStatus = $this->webhookRequest->payload['status'] ?? null;
        $quoteTotal = $this->webhookRequest->payload['total'] ?? null;
        $quoteMemo = $this->webhookRequest->payload['memo'] ?? null;
        $entityId = $this->webhookRequest->payload['entity'] ?? null;

        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');
        $updatedOrder = null;

        $successMessage = 'NetSuite Quote Not Synced';
        if ($mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);

            $pullNetSuiteQuote = new PullNetSuiteQuoteToOrderAction(
                $this->receiver->app,
                $mainCompany
            );

            try {
                $updatedOrder = $pullNetSuiteQuote->execute($quoteId);
                $successMessage = 'NetSuite Quote Synced';
            } catch (Exception $e) {
                report($e);
                $successMessage = 'NetSuite Quote Sync Failed: ' . $e->getMessage();
            }
        }

        return [
            'message' => $successMessage,
            'netsuite_quote_id' => $quoteId,
            'netsuite_quote_number' => $quoteTranId,
            'netsuite_quote_status' => $quoteStatus,
            'netsuite_quote_total' => $quoteTotal,
            'netsuite_entity_id' => $entityId,
            'mainCompanyId' => $mainCompanyId,
            'updatedOrder' => $updatedOrder ? [
                'id' => $updatedOrder->id,
                'order_number' => $updatedOrder->getOrderNumber(),
                'total_gross_amount' => $updatedOrder->total_gross_amount,
                'fulfillment_status' => $updatedOrder->fulfillment_status,
                'customer_note' => $updatedOrder->customer_note,
            ] : null,
        ];
    }
}
