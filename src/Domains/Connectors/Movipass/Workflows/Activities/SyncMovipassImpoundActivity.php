<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncMovipassImpoundActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->status === OrderStatusEnum::COMPLETED->value && $order->orderType->name === OrderTypeEnum::IMPOUND_LOT->value) {
                    // lets add the order number to the reference field if the order number is not already set
                    if ($order->reference && ! str_contains($order->reference, "#" . $order->order_number)) {
                        $order->reference = $order->reference . ' - #' . $order->order_number;
                    }

                    // lets store the charge data
                    $order->metadata = [
                        ...$order->metadata ?? [],
                        'data' => [
                            ...$order->metadata['data'] ?? [],
                            'terms_and_conditions' => true,
                        ],
                    ];

                    $order->saveQuietly();
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order synced correctly',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }
}
