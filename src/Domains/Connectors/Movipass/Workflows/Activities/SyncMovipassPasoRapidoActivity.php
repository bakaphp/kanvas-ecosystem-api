<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Actions\UpdateVehicleTagTelemetryAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncMovipassPasoRapidoActivity extends KanvasActivity implements WorkflowActivityInterface
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
                if ($order->orderType?->name !== OrderTypeEnum::PASO_RAPIDO->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a paso_rapido order',
                    ];
                }

                // Bulk corporate recharges run their per-vehicle updates inside
                // BulkRechargeOrderTagsAction during the per-TAG loop.
                if (($order->metadata['data']['is_bulk_recharge'] ?? false) === true) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Bulk recharge handled inline by BulkRechargeOrderTagsAction',
                    ];
                }

                if ($order->payment_status !== PaymentStatusEnum::PAID->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Order is not paid yet',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                $toStatus = $params['to_status'] ?? null;

                $shouldSync = $eventName === WorkflowEnum::UPDATED->value
                    || ($eventName === WorkflowEnum::STATUS_TRANSITION->value
                        && $toStatus === PaymentStatusEnum::PAID->value);

                if (! $shouldSync) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Event does not require telemetry sync',
                        'event' => $eventName,
                    ];
                }

                if (($order->metadata['data']['vehicle_telemetry_synced_at'] ?? null) !== null) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Vehicle telemetry already synced for this order',
                    ];
                }

                $tag = (string) ($order->metadata['data']['paso_rapido_tag'] ?? '');

                if ($tag === '') {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Order has no paso_rapido_tag in metadata',
                    ];
                }

                $vehicle = $this->resolveVehicleProduct($order);

                if (! $vehicle instanceof Products) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'No vehicle product found among order items',
                    ];
                }

                $result = new UpdateVehicleTagTelemetryAction(
                    $vehicle,
                    $order,
                    $app,
                    $tag,
                    (float) $order->getTotalAmount(),
                )->execute();

                $order->metadata = [
                    ...$order->metadata ?? [],
                    'data' => [
                        ...$order->metadata['data'] ?? [],
                        'vehicle_telemetry_synced_at' => now()->toIso8601String(),
                        'vehicle_telemetry_result' => $result,
                    ],
                ];
                $order->saveQuietly();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Vehicle telemetry synced',
                    'result' => $result,
                ];
            },
            company: $order->company,
        );
    }

    /**
     * PASO_RAPIDO orders carry the vehicle as an order item with productsType
     * slug = 'vehicle'. Walk the items and return the first vehicle product.
     */
    private function resolveVehicleProduct(Order $order): ?Products
    {
        foreach ($order->items as $item) {
            $product = $item->variant?->product;
            if ($product instanceof Products && $product->productsType?->slug === 'vehicle') {
                return $product;
            }
        }

        return null;
    }
}
