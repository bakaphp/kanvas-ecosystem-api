<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class ExtendReservationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                if (! isset($order->parent_id)) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'error',
                        'message' => 'Related order not found',
                    ];
                }

                try {
                    $mainReservation = Order::find($order->parent_id);
                    if ($mainReservation && $order->status === OrderStatusEnum::COMPLETED->value) {
                        $latestEnd = Order::where('id', $mainReservation->id)
                            ->orWhere('parent_id', $mainReservation->id)
                            ->whereNotNull('metadata')
                            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
                            ->max(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at'))"));

                        $mainReservation->update([
                            'metadata->data->end_at' => $latestEnd,
                            'metadata->data->original_end_at' => $mainReservation->metadata['data']['original_end_at'] ?? $mainReservation->metadata['data']['end_at'],
                        ]);
                    }

                    return [
                        'order' => $mainReservation->getId(),
                        'status' => 'success',
                        'message' => 'Reservation extended',
                    ];
                } catch (Throwable $e) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'error',
                        'message' => $e->getMessage(),
                        'report' => 'fail',
                        'trace' => $e->getTraceAsString(),
                    ];
                }
            },
            company: $order->company,
        );
    }
}
