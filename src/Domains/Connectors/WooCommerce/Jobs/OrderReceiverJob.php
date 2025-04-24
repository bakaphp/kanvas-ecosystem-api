<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Jobs;

use Kanvas\Connectors\WooCommerce\Actions\CreateOrderAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class OrderReceiverJob extends ProcessWebhookJob
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
        $createOrder = new CreateOrderAction(
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
            'message' => 'Order created successfully',
            'order'   => $createOrder->execute()->getId(),
        ];
    }
}
