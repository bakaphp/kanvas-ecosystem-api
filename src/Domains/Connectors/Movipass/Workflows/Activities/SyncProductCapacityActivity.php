<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Actions\GetSlotAvailabilityAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncProductCapacityActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $product, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            integrationOperation: function () use ($product, $app) {
                /** @var Products $product */
                $totalMax = 0;
                $totalOccupied = 0;
                $totalAvailable = 0;

                foreach ($product->variants()->get() as $variant) {
                    $variantWarehouse = $variant->variantWarehouses()->first();

                    if (! ($variantWarehouse?->max_capacity > 0)) {
                        continue;
                    }

                    $slotData = new GetSlotAvailabilityAction($variant, $app)->execute();
                    $totalMax += $slotData->maxCapacity;
                    $totalOccupied += $slotData->occupiedCapacity;
                    $totalAvailable += $slotData->availableCapacity;

                    $variant->updateQuantityInWarehouse($variantWarehouse->warehouse, $slotData->availableCapacity);
                }

                $product->addAttribute('capacity', [
                    'occupiedParkingSpaces' => $totalOccupied,
                    'availableParkingSpaces' => $totalAvailable,
                    'totalParkingSpaces' => $totalMax,
                ]);

                return [
                    'product' => $product->getId(),
                    'status' => 'success',
                    'totalParkingSpaces' => $totalMax,
                    'occupiedParkingSpaces' => $totalOccupied,
                    'availableParkingSpaces' => $totalAvailable,
                ];
            }
        );
    }
}
