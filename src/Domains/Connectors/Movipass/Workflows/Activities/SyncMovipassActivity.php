<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\RecalculateVariantWarehouseQuantityAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncMovipassActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->orderType->name !== OrderTypeEnum::MOVIPASS->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a movipass order',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                $toStatus  = $params['to_status'] ?? null;

                if ($eventName === WorkflowEnum::CREATED->value) {
                    if ($order->reference && ! str_contains($order->reference, "#" . $order->order_number)) {
                        $order->reference = $order->reference . ' ' . $order->metadata['data']['vehiclePlate'] ?? '' . ' - #' . $order->order_number;
                    }

                    $order->metadata = [
                        ...$order->metadata ?? [],
                        'data' => [
                            ...$order->metadata['data'] ?? [],
                            'terms_and_conditions' => true,
                        ],
                    ];

                    if ($order->metadata['data']['is_manual'] ?? false) {
                        $order->transitionToStatus(
                            $order->user,
                            MovipassOrderStatusEnum::ACTIVE->value
                        );
                        $order->saveQuietly();
                        // recalculation handled by STATUS_TRANSITION → active event
                    } else {
                        $order->saveQuietly();
                    }
                }

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    if ($toStatus === MovipassOrderStatusEnum::PAID->value) {
                        $order->metadata = [
                            ...$order->metadata ?? [],
                            'data' => [
                                ...$order->metadata['data'] ?? [],
                                'payment_date' => Carbon::now()->setTimezone('America/Santo_Domingo')->format('d/m/Y h:i A'),
                            ],
                        ];
                        $order->fulfill();
                        if ($order->metadata['data']['is_manual'] ?? false) {
                            $order->transitionToStatus(
                                $order->user,
                                MovipassOrderStatusEnum::COMPLETED->value
                            );
                        } else {
                            $order->transitionToStatus(
                                $order->user,
                                MovipassOrderStatusEnum::ACTIVE->value
                            );
                        }
                    }

                    if (in_array($toStatus, [
                        MovipassOrderStatusEnum::ACTIVE->value,
                        MovipassOrderStatusEnum::COMPLETED->value,
                        MovipassOrderStatusEnum::CANCELLED->value,
                    ])) {
                        new RecalculateVariantWarehouseQuantityAction($order, $app)->execute();
                    }
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order synced correctly',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                    'to_status' => $toStatus,
                    'event_name' => $eventName,
                ];
            },
            company: $order->company,
        );
    }
}
