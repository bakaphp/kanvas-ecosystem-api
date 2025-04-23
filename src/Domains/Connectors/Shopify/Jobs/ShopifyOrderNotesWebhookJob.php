<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Services\ShopifyOrderService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ShopifyOrderNotesWebhookJob extends ProcessWebhookJob
{
    #[Override]
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

        $orderId = $this->webhookRequest->payload['orderId'] ?? null;
        $note = $this->webhookRequest->payload['note'] ?? null;

        if (!$orderId || !$note) {
            return [
                'message' => 'Invalid payload missing orderId or note',
            ];
        }

        $notesAttributes = is_array($note) ? $note : [];
        $note = !is_array($note) ? $note : '';
        $orderId = $this->extractShopifyId($orderId);

        $order = $shopifyOrderService->addNoteToOrder($orderId, $note, $notesAttributes);

        return [
            'message' => 'Note added to order '.$orderId,
            'order'   => $order,
        ];
    }

    public function extractShopifyId(string $orderId): string
    {
        if (preg_match('/gid:\/\/shopify\/\w+\/(\d+)/', $orderId, $matches)) {
            return $matches[1];
        }

        return $orderId;
    }
}
