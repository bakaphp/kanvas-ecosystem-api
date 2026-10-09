<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class ExternalVehicleModel
{
    public function __construct(
        public readonly string $brand,
        public readonly string $model,
        public readonly string $externalId,
    ) {
    }
}
