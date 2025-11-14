<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class Vehicle extends Data
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $customerName,
        public string $brand,
        public string $model,
        public string $fullName,
        public string $year,
        public VehiclePrice $price,
        public string $condition,
        public string $fuelType,
        public string $type,
        public string $transmission,
        public VehicleEngine $engine,
        public VehicleColors $colors,
        public VehicleSpecifications $specifications,
        public VehicleImages $images,
        public VehicleLinks $links,
        public string $description,
        public array $components,
        public VehicleFinancing $financing,
        public VehicleContact $contact,
        public VehicleVideo $video,
        public bool $isSpecial = false
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['Id'],
            customerId: $data['Customer'],
            customerName: $data['CustomerName'],
            brand: $data['BrandName'],
            model: $data['ModelName'],
            fullName: $data['FullName'],
            year: $data['Year'],
            price: VehiclePrice::from($data),
            condition: $data['Condition'],
            fuelType: $data['Fuel'],
            type: $data['Type'],
            transmission: $data['Transmission'],
            engine: VehicleEngine::from($data),
            colors: VehicleColors::from($data),
            specifications: VehicleSpecifications::from($data),
            images: VehicleImages::from($data),
            links: VehicleLinks::from($data),
            description: $data['Description'] ?? '',
            components: $data['Components'] ?? [],
            financing: VehicleFinancing::from($data),
            contact: VehicleContact::from($data),
            video: VehicleVideo::from($data),
            isSpecial: $data['IsSpecial'] ?? false
        );
    }
}