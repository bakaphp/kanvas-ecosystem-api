<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Jobs;

use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProductReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $payload = json_encode($this->webhookRequest->payload);
        $payload = json_decode($payload);
        if (! $payload->status === 'completed') {
            return [
                'message' => 'Order not completed',
            ];
        }
        $createProduct = new CreateProductAction(
            $this->receiver->app,
            $this->receiver->company,
            $this->receiver->user,
            Regions::getById(
                $this->receiver->configuration['region_id'],
                $this->receiver->app
            ),
            $payload
        );

        return [
            'message' => 'Product created successfully',
            'order' => $createProduct->execute()->getId(),
        ];
    }
}
