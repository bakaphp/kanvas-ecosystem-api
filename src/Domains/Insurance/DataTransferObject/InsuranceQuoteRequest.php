<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Inventory\Variants\Models\Variants;

class InsuranceQuoteRequest
{
    public function __construct(
        public readonly string $product,
        public readonly array $payload = [],
        public readonly ?Variants $vehicle = null,
        public readonly bool $rebuildVehicle = false,
    ) {
    }
}
