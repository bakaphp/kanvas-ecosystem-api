<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\DataTransferObject;

use Spatie\LaravelData\Data;

class EngineData extends Data
{
    public function __construct(
        public readonly ?int $cylinders,
        public readonly ?string $type,
        public readonly ?string $fuelType,
        public readonly ?string $displacement,
        public readonly ?int $horsepower,
        public readonly ?int $torque,
    ) {
    }
}
