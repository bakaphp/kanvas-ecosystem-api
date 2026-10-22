<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class ExternalVehicleReference
{
    public function __construct(
        public readonly ?string $brandId,
        public readonly string $modelId,
        public readonly ?string $versionId,
    ) {
    }
}
