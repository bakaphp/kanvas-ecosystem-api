<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Insurance\Services\VehicleAttributeReader;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Kanvas\Inventory\Products\Models\Products;

class CanonicalVehicle
{
    public function __construct(
        public readonly string $brand,
        public readonly string $model,
        public readonly ?int $year = null,
        public readonly ?string $version = null,
    ) {
    }

    public static function fromProduct(Products $vehicle): self
    {
        $year = VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::YEAR);

        return new self(
            brand: (string) VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::BRAND),
            model: (string) VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::MODEL),
            year: is_numeric($year) ? (int) $year : null,
            version: VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::VERSION),
        );
    }

    public function brandKey(): string
    {
        return VehicleKeyNormalizer::normalize($this->brand);
    }

    public function modelKey(): string
    {
        return VehicleKeyNormalizer::normalize($this->model);
    }

    public function versionKey(): string
    {
        return VehicleKeyNormalizer::normalize((string) $this->version);
    }

    public function isIdentifiable(): bool
    {
        return $this->brandKey() !== '' && $this->modelKey() !== '';
    }
}
