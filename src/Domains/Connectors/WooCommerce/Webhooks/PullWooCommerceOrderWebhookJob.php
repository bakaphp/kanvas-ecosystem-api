<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Webhooks;

use Exception;
use Kanvas\Connectors\WooCommerce\Actions\PullOrderFromWooCommerceAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullWooCommerceOrderWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $orderId = $this->webhookRequest->payload['order_id'] ?? null;
        $regionId = $this->receiver->configuration['region_id'] ?? null;
        $runWorkflow = (bool) ($this->webhookRequest->payload['run_workflow'] ?? true);

        if (! $orderId) {
            return [
                'message' => 'Missing order_id in payload',
                'status' => 'error',
            ];
        }

        if (! $regionId) {
            return [
                'message' => 'Missing region_id in webhook configuration',
                'status' => 'error',
            ];
        }

        try {
            $region = Regions::getById($regionId, $this->receiver->app);
        } catch (Exception $e) {
            return [
                'message' => 'Region not found',
                'status' => 'error',
            ];
        }

        try {
            $pullOrderAction = new PullOrderFromWooCommerceAction(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                region: $region,
                wooCommerceOrderId: (int) $orderId,
                runWorkflows: false,
            );

            $order = $pullOrderAction->execute();

            /**
             * @todo make this dynamic for affiliate fields
             */
            if (! empty($this->webhookRequest->payload['affiliate_id'])) {
                $order->addMetadata('affiliate_id', $this->webhookRequest->payload['affiliate_id']);
            }

            if (! empty($this->webhookRequest->payload['country'])) {
                $order->addMetadata('affiliate_shortcode', $this->webhookRequest->payload['country']);
                $order->addMetadata('affiliate_link_code', $this->webhookRequest->payload['country']);
            }

            if ($runWorkflow) {
                $order->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true,
                    [
                        'app' => $this->receiver->app,
                        'company' => $this->receiver->company,
                    ]
                );
            }

            return [
                'message' => 'Order pulled successfully',
                'order_id' => $order->id,
                'woocommerce_order_id' => $orderId,
                'status' => 'success',
            ];
        } catch (Exception $e) {
            return [
                'message' => 'Error pulling order: ' . $e->getMessage(),
                'woocommerce_order_id' => $orderId,
                'status' => 'error',
            ];
        }
    }
}
