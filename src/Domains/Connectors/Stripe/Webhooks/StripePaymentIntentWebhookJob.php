<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceOrderService;
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
        $chargeId = $payload['data']['object']['latest_charge'] ?? null;
        $clientSecret = $payload['data']['object']['client_secret'] ?? null;
        sleep(15);
        if ($chargeId === null) {
            return [
                'message' => 'No charge ID found in the payload',
                'response' => null,
            ];
        }

        $order = Order::fromApp($this->receiver->app)
        ->where('metadata', 'LIKE', '%' . $clientSecret . '%')
        ->first();

        if (empty($order)) {
            return [
                'clientSecret' => $clientSecret,
                'chargeId' => $chargeId,
                //'order' => $order->toArray(),
                'message' => 'Order not found',
                'response' => null,
            ];
        }
        $orderCommerceId = $order->get(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value);

        $order->addPrivateMetadata('stripe_payment_intent', $payload);

        $commerceOrder = new WooCommerceOrderService($order->app);
        $response = $commerceOrder->updateOrderStripePayment(
            $orderCommerceId,
            $chargeId,
            'completed'
        );

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
