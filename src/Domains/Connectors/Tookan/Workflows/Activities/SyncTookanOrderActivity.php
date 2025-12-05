<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Tookan\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateExternalOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncTookanOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::TOOKAN,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->orderType->name !== OrderTypeEnum::DELIVERY->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a delivery order, skipping sync',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                if ($eventName == WorkflowEnum::CREATED->value) {
                    $externalItem = $order->items->first(function ($item) use ($order) {
                        return $item->variant->companies_id !== $order->companies_id;
                    });

                    $childOrder = new CreateExternalOrderAction(
                        order: $order,
                        orderItem: $externalItem
                    )->execute();
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
