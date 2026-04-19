<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Drivers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\LicensePlateExtractor\Contracts\PlateExtractorDriverInterface;
use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Kanvas\Connectors\PlateRecognizer\Services\VehicleRecognitionService;
use Override;
use Throwable;

class PlateRecognizerDriver implements PlateExtractorDriverInterface
{
    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null,
    ) {
    }

    #[Override]
    public function extract(string $imageUrl): ?LicensePlate
    {
        try {
            $service = new VehicleRecognitionService($this->app, $this->company);
            $vehicle = $service->processVehicleImages([$imageUrl]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($vehicle === null) {
            return null;
        }

        return new LicensePlate(
            plateNumber: $vehicle->plateNumber,
            provider: ProviderEnum::PLATE_RECOGNIZER,
            confidence: $vehicle->confidence,
            region: $vehicle->region ?: null,
            make: $vehicle->make ?: null,
            model: $vehicle->model ?: null,
            color: $vehicle->color ?: null,
            type: $vehicle->type ?: null,
            rawResponse: $vehicle->rawData,
        );
    }
}
