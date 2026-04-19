<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\DataTransferObject;

use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Spatie\LaravelData\Data;

class LicensePlate extends Data
{
    public function __construct(
        public readonly string $plateNumber,
        public readonly ProviderEnum $provider,
        public readonly float $confidence = 0.0,
        public readonly ?string $region = null,
        public readonly ?string $make = null,
        public readonly ?string $model = null,
        public readonly ?string $color = null,
        public readonly ?string $type = null,
        public readonly array $rawResponse = [],
    ) {
    }
}
