<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\RecalculateSlotCapacityAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
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
                    if ($order->reference && ! str_contains($order->reference, '#' . $order->order_number)) {
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
                        // recalculation handled by STATUS_TRANSITION → active event
                    }

                    $order->saveQuietly();
                }

                if ($eventName === WorkflowEnum::UPDATED->value) {
                    $endAt    = $order->metadata['data']['end_at'] ?? null;
                    $isManual = $order->metadata['data']['is_manual'] ?? false;

                    if ($isManual && $endAt && ! $order->orderStatus?->is_final) {
                        // Manual order updated with end_at → session is over, complete immediately
                        $order->transitionToStatus(
                            $order->user,
                            MovipassOrderStatusEnum::COMPLETED->value
                        );
                        $order->saveQuietly();
                    } elseif (! $isManual && $endAt
                        && $order->payment_status === PaymentStatusEnum::PAID->value
                        && $order->orderStatus?->slug === MovipassOrderStatusEnum::CREATED->value
                    ) {
                        // Non-manual order: payment received + end_at already set → activate
                        $order->transitionToStatus(
                            $order->user,
                            MovipassOrderStatusEnum::ACTIVE->value
                        );
                        $order->saveQuietly();
                    }
                }

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    if (in_array($toStatus, [
                        MovipassOrderStatusEnum::ACTIVE->value,
                        MovipassOrderStatusEnum::COMPLETED->value,
                        MovipassOrderStatusEnum::CANCELLED->value,
                    ])) {
                        new RecalculateSlotCapacityAction($order, $app)->execute();
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
