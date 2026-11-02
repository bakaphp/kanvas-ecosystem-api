<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Inventory\Products\Models\Products;

class StampVehicleInsuranceProfileAction
{
    public function __construct(
        protected Products $vehicle,
        protected VehicleInsuranceProfile $profile,
    ) {
    }

    public function execute(): Products
    {
        $attributes = array_values(array_filter([
            $this->attribute(VehicleInsuranceAttributeEnum::FUEL_TYPE, $this->profile->fuelType?->value),
            $this->attribute(
                VehicleInsuranceAttributeEnum::IS_ZERO_KM,
                $this->profile->isZeroKm === null ? null : ($this->profile->isZeroKm ? '1' : '0')
            ),
            $this->attribute(
                VehicleInsuranceAttributeEnum::ESTIMATED_VALUE,
                $this->profile->estimatedValue === null ? null : (string) $this->profile->estimatedValue
            ),
        ]));

        if ($attributes === []) {
            return $this->vehicle;
        }

        $this->vehicle->addAttributes($this->vehicle->user, $attributes);
        $this->vehicle->save();

        return $this->vehicle;
    }

    protected function attribute(VehicleInsuranceAttributeEnum $attribute, ?string $value): ?array
    {
        return $value === null ? null : ['name' => $attribute->value, 'value' => $value];
    }
}
