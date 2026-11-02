<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Insurance\Services\VehicleAttributeReader;
use Kanvas\Inventory\Products\Models\Products;

class VehicleInsuranceProfile
{
    public function __construct(
        public readonly ?VehicleFuelTypeEnum $fuelType = null,
        public readonly ?bool $isZeroKm = null,
        public readonly ?float $estimatedValue = null,
    ) {
    }

    public static function fromProduct(Products $vehicle): self
    {
        $zeroKm = VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::IS_ZERO_KM);
        $value = VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::ESTIMATED_VALUE);
        $fuel = VehicleAttributeReader::read($vehicle, VehicleInsuranceAttributeEnum::FUEL_TYPE);

        return new self(
            fuelType: $fuel !== null ? VehicleFuelTypeEnum::tryFrom($fuel) : null,
            isZeroKm: $zeroKm !== null ? filter_var($zeroKm, FILTER_VALIDATE_BOOLEAN) : null,
            estimatedValue: is_numeric($value) ? (float) $value : null,
        );
    }
}
