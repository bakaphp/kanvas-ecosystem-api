<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mindee\DataTransferObjects;

use Spatie\LaravelData\Data;

class Tag extends Data
{
    public function __construct(
        public readonly ?string $vehicleIdentificationNumber = null, // VIN
        public readonly ?string $manufactureDate = null,
        public readonly ?string $licensePlateNumber = null,
        public readonly ?string $vehicleColor = null,
        public readonly ?string $insuranceCompany = null,
        public readonly ?string $make = null,
        public readonly ?string $model = null,
        public readonly ?string $owner = null,
        public readonly ?string $ownerId = null,
        public readonly ?string $imageUrl = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        // Extract the prediction data
        $predictionData = $data['document']['inference']['prediction'] ?? [];

        return new self(
            vehicleIdentificationNumber: $predictionData['vehicle_identification_number']['value'] ?? null,
            manufactureDate: $predictionData['manufacture_date']['value'] ?? null,
            licensePlateNumber: $predictionData['license_plate_number']['value'] ?? null,
            vehicleColor: $predictionData['vehicle_color']['value'] ?? null,
            insuranceCompany: $predictionData['insurance_company']['value'] ?? null,
            make: $predictionData['make']['value'] ?? null,
            model: $predictionData['model']['value'] ?? null,
            owner: $predictionData['owner']['value'] ?? null,
            ownerId: $predictionData['owner_id']['value'] ?? null,
            imageUrl: $data['image_url'] ?? null
        );
    }
}
