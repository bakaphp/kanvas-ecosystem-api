<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\VehicleCatalogScan;

interface TargetedVehicleCatalogProviderInterface
{
    public function scanVehicleCatalog(array $vehicles): VehicleCatalogScan;
}
