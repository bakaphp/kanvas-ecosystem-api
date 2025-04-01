<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class StripePaymentIntentWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        //$regionId = $this->receiver->configuration['region_id'];
        $payload = $this->webhookRequest->payload;
        $chargeId = $payload['latest_charge'] ?? null;

        if ($chargeId === null) {
            return [
                'message' => 'No charge ID found in the payload',
                'response' => null,
            ];
        }

        $order = Order::fromApp($this->receiver->app)->where('checkout_token', $payload['id'])->first();

        if (empty($order)) {
            return [
                'message' => 'Order not found',
                'response' => null,
            ];
        }

        $order->fireWorkflow(
            WorkflowEnum::AFTER_PAYMENT_INTENT->value,
            true,
            [
                'app' => $order->app,
                'company' => $order->company,
            ]
        );

        return [
            'message' => 'Payment intent processed successfully',
            'order' => $order->toArray(),
        ];
    }
}
