<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Services\ShopifyOrderService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ShopifyOrderNotesWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $integrationCompanyId = $this->receiver->configuration['integration_company_id'];
        $integrationCompany = IntegrationsCompany::getById($integrationCompanyId);

        $warehouses = Warehouses::where('regions_id', $integrationCompany->region_id)
                                ->fromCompany($integrationCompany->company)
                                ->fromApp($this->receiver->app)
                                ->firstOrFail();

        $shopifyOrderService = new ShopifyOrderService(
            app: $this->receiver->app,
            company: $integrationCompany->company,
            warehouses: $warehouses,
        );

        $orderId = $this->webhookRequest->payload['orderId'];
        $note = $this->webhookRequest->payload['note'];

        if (! $orderId || ! $note) {
            return [
                'message' => 'Invalid payload missing orderId or note',
            ];
        }

        $order = $shopifyOrderService->addNoteToOrder($orderId, $note);

        return [
            'message' => 'Note added to order ' . $orderId,
            'order' => $order,
        ];
    }
}
