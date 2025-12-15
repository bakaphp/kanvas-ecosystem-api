<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\DataTransferObject;

use Spatie\LaravelData\Data;

class StyleData extends Data
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $name,
        public readonly ?int $year,
        public readonly ?string $division,
        public readonly ?string $subdivision,
        public readonly ?string $model,
        public readonly ?string $trim,
        public readonly ?string $driveTrain,
        public readonly ?int $passDoors,
        public readonly ?string $stockImage,
        public readonly ?PriceData $basePrice,
    ) {
    }
}
