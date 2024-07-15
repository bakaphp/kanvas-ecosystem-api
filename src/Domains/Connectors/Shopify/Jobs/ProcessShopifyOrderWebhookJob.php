<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProcessShopifyOrderWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $regionId = $this->receiver->configuration['region_id'];
        $syncShopifyOrder = new SyncShopifyOrderAction(
            $this->receiver->app,
            $this->receiver->company,
            Regions::getById($regionId),
            $this->webhookRequest->payload
        );

        $order = $syncShopifyOrder->execute();

        return [
            'message' => 'Order synced successfully',
            'order' => $order->getId(),
        ];
    }
}
