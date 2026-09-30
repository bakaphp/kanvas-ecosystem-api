<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Spatie\LaravelData\Data;

class Parcel extends Data
{
    public function __construct(
        public readonly int $grams,
        public readonly ?float $lengthCm = null,
        public readonly ?float $widthCm = null,
        public readonly ?float $heightCm = null,
    ) {
    }
}
