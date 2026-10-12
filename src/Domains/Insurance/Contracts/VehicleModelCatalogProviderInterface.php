<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

interface VehicleModelCatalogProviderInterface
{
    public function vehicleModelEntries(): array;
}
