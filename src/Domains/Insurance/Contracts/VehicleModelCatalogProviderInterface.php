<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\ExternalVehicleModel;

interface VehicleModelCatalogProviderInterface
{
    /** @return list<ExternalVehicleModel> */
    public function vehicleModelEntries(): array;
}
