<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleImages extends Data
{
    public function __construct(
        public string $main,
        public string $mainBig,
        public array $gallery
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            main: $data['MainPhotoUrl'] ?? '',
            mainBig: $data['MainPhotoUrlBig'] ?? '',
            gallery: $data['Photos'] ?? []
        );
    }
}