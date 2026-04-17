<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Kanvas\Connectors\ChromeData\Services\VehicleService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class DecodeVinActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (empty($entity->company->get(ConfigurationEnum::ACCOUNT_NUMBER->value))) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'ChromeData not configured for this company.',
                'company_id' => $entity->company?->getId(),
            ]);
        }

        $vin = $params['vin'] ?? null;

        if (empty($vin)) {
            $variant = $entity->variants()->first();
            if (! $variant) {
                return $this->failWorkflow([
                    'success' => false,
                    'message' => 'No VIN provided and entity has no variant.',
                    'entity_id' => $entity->getId(),
                ]);
            }
            $vin = $variant->sku;
        }

        if (empty($vin)) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'VIN is empty.',
                'entity_id' => $entity->getId(),
            ]);
        }

        $includeMediaGallery = (bool) ($params['include_media_gallery'] ?? false);
        $skipCache = (bool) ($params['skip_cache'] ?? false);

        $vehicleService = new VehicleService($app, $entity->company);
        $vehicleData = $vehicleService->getVehicleInfoByVin($vin, $includeMediaGallery, $skipCache);

        if ($vehicleData === null) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'No vehicle data found for this VIN.',
                'entity_id' => $entity->getId(),
                'vin' => $vin,
            ]);
        }

        return [
            'success' => true,
            'message' => 'VIN decoded successfully.',
            'entity_id' => $entity->getId(),
            'vin' => $vin,
            'year' => $vehicleData->year,
            'make' => $vehicleData->make,
            'model' => $vehicleData->model,
            'trim' => $vehicleData->trim,
            'style_name' => $vehicleData->styleName,
            'body_style' => $vehicleData->bodyStyle,
            'drive_train' => $vehicleData->driveTrain,
            'pass_doors' => $vehicleData->passDoors,
            'stock_image' => $vehicleData->stockImage,
            'engine' => $vehicleData->engine?->toArray(),
            'exterior_colors' => array_map(fn ($c) => $c->toArray(), $vehicleData->exteriorColors),
            'interior_colors' => array_map(fn ($c) => $c->toArray(), $vehicleData->interiorColors),
            'base_price' => $vehicleData->basePrice?->toArray(),
            'styles' => array_map(fn ($s) => $s->toArray(), $vehicleData->styles),
            'response_status' => $vehicleData->responseStatus,
        ];
    }
}
