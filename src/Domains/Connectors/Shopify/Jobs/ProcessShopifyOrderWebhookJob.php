<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessShopifyOrderWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $regionId = $this->receiver->configuration['region_id'];
        $tags = $this->receiver->configuration['tag'] ?? null;
        $isB2BOrder = (bool) ($this->receiver->configuration['is_b2b_order'] ?? false);

        $syncShopifyOrder = new SyncShopifyOrderAction(
            $this->receiver->app,
            $this->receiver->company,
            Regions::getById($regionId),
            $this->webhookRequest->payload,
            $tags
        );

        $order = $syncShopifyOrder->execute();

        if ($isB2BOrder) {
            $order->addTags(['B2B']);
        }

        return [
            'message' => 'Order synced successfully',
            'order' => $order->getId(),
        ];
    }
}
