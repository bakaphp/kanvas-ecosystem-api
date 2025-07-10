<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
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
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->orderType->name !== OrderTypeEnum::IMPOUND_LOT->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not an impound lot',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                if ($eventName == WorkflowEnum::CREATED->value) {
                    // lets add the order number to the reference field if the order number is not already set
                    if ($order->reference && ! str_contains($order->reference, "#" . $order->order_number)) {
                        $order->reference = $order->reference . ' - #' . $order->order_number;
                    }

                    // lets store the charge data
                    $variant = $order->items->first(function ($item) {
                        return $item->variant->product?->attributes
                        ->contains(fn ($attribute) => in_array($attribute->slug, ['late-fee-variant-id']) && ! empty($attribute->value));
                    })?->variant;

                    $graceStartAt = now('America/New_York')->startOfDay()->addDays(1);

                    $order->metadata = [
                        ...$order->metadata ?? [],
                        'data' => [
                            ...$order->metadata['data'] ?? [],
                            'terms_and_conditions' => true,
                            ...$variant ? [
                                'late-fee-variant-id' => $variant->getAttributeBySlug('late-fee-variant-id')?->value,
                                'late_fee_grace_start_at' => $graceStartAt->toDateTimeString()
                            ] : [],
                        ],
                    ];

                    $order->saveQuietly();
                }

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    $toStatus = $params['to_status'] ?? null;

                    if ($toStatus === MovipassOrderStatusEnum::RELEASED->value) {
                        $order->fulfill();
                    }
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
