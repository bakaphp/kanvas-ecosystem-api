<?php

namespace Kanvas\Connectors\Tookan\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Tookan\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateExternalOrderAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CreateTookanOrderActivity extends KanvasActivity implements WorkflowActivityInterface
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
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                if ($order->orderType->name !== OrderTypeEnum::DELIVERY->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a delivery order, skipping sync',
                    ];
                }

                $externalItem = $order->items->first(function ($item) use ($order) {
                    return $item->variant->companies_id !== $order->companies_id;
                });

                new CreateExternalOrderAction(
                    order: $order,
                    orderItem: $externalItem
                )->execute();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order created successfully',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }
}
