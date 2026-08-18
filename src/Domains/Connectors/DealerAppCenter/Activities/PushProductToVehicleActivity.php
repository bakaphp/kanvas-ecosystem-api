<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerAppCenter\Actions\MapProductToVehicleAction;
use Kanvas\Connectors\DealerAppCenter\Actions\PushVehicleToDealerAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Workflow-facing entry point for the reverse migration: Kanvas Product -> dealer-api `vehicles` row.
 * Mapping/insert logic is shared with MigrateProductsToVehiclesCommand via MapProductToVehicleAction
 * and PushVehicleToDealerAction — this Activity only adds the workflow retry/integration wrapper.
 */
#[WorkflowAction]
class PushProductToVehicleActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param array{rooftop_id?: int} $params
     * @return array<array-key, mixed>
     */
    public function execute(Products $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        // rooftop_id can be forced via params, but the standard path is the same
        // `companies_settings` lookup dealer-api's own VehiclesTask uses for the opposite direction.
        $rooftopId = (int) ($params['rooftop_id'] ?? $entity->company->get('DEALER_LEGACY_ROOFTOP') ?? 0);
        if ($rooftopId === 0) {
            return $this->failWorkflow([
                'message' => 'No rooftop_id given and company has no DEALER_LEGACY_ROOFTOP setting',
                'entity' => null,
            ]);
        }

        $variant = $entity->variants()->first();
        if (! $variant) {
            return $this->failWorkflow([
                'message' => 'Product has no variant to migrate',
                'entity' => null,
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::DEALER_APP_CENTER,
            integrationOperation: function () use ($entity, $variant, $rooftopId): array {
                $dealerConnection = PushVehicleToDealerAction::resolveDealerConnection();

                $mapped = new MapProductToVehicleAction(
                    $entity,
                    $variant,
                    $rooftopId,
                    $dealerConnection,
                )->execute();

                $vehicleId = new PushVehicleToDealerAction($mapped, $dealerConnection)->execute();

                return [
                    'vehicle_id' => $vehicleId,
                    'vin' => $mapped['vehicle']['vin'],
                ];
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
