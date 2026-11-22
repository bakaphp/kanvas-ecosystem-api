<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\RecordParkingPassScanAction;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class ParkingPassScannedActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $booking, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $booking,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function (EventVersion $booking) use ($params): array {
                $order = $booking->event->orders()->notDeleted()->latest('id')->first();

                if ($order === null || ! WalkInOrder::is($order)) {
                    return $this->skipWorkflow('Booking has no walk-in parking order');
                }

                $recorded = new RecordParkingPassScanAction(
                    order: $order,
                    useNumber: (int) $params['use_number'],
                    maxUses: (int) $params['max_uses'],
                    scannedAt: Carbon::parse($params['scanned_at']),
                )->execute();

                if (! $recorded) {
                    return $this->skipWorkflow('Scan already recorded or order not in a state to record it');
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Parking scan recorded',
                    'use_number' => (int) $params['use_number'],
                ];
            },
            company: $booking->company,
        );
    }
}
