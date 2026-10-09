<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DateTimeImmutable;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\InsuranceUnderwritingFieldEnum;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Inventory\Products\Models\Products;

class CanonicalQuoteInput
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly CanonicalVehicle $vehicle,
        public readonly VehicleInsuranceProfile $profile,
        public readonly InsuranceCoverageCategoryEnum $category,
        public readonly array $underwriting = [],
    ) {
    }

    public static function fromMultiple(AppInterface $app, CompanyInterface $company, array $data): self
    {
        $vehicleData = (array) ($data['vehicle'] ?? []);
        [$vehicle, $profile] = isset($vehicleData['product_id'])
            ? self::fromProductId((int) $vehicleData['product_id'], $app, $company)
            : self::fromAttributes($vehicleData);

        if (! $vehicle->isIdentifiable()) {
            throw new ValidationException('A vehicle brand and model are required to search for insurance');
        }

        return new self(
            app: $app,
            company: $company,
            vehicle: $vehicle,
            profile: $profile,
            category: InsuranceCoverageCategoryEnum::from((string) $data['category']),
            underwriting: self::underwritingFrom((array) ($data['underwriting'] ?? [])),
        );
    }

    public function underwritingValue(InsuranceUnderwritingFieldEnum $field): ?string
    {
        $value = $this->underwriting[$field->value] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function signature(): string
    {
        $underwriting = $this->underwriting;
        unset($underwriting[InsuranceUnderwritingFieldEnum::IP_ADDRESS->value]);
        ksort($underwriting);

        return md5((string) json_encode([
            $this->vehicle->brandKey(),
            $this->vehicle->modelKey(),
            $this->vehicle->year,
            $this->profile->fuelType?->value,
            $this->profile->isZeroKm,
            $this->profile->estimatedValue,
            $this->category->value,
            $underwriting,
        ]));
    }

    private static function fromProductId(int $productId, AppInterface $app, CompanyInterface $company): array
    {
        $product = Products::getByIdFromCompanyApp($productId, $company, $app);

        return [CanonicalVehicle::fromProduct($product), VehicleInsuranceProfile::fromProduct($product)];
    }

    private static function fromAttributes(array $vehicleData): array
    {
        $fuel = $vehicleData['fuel_type'] ?? null;

        return [
            new CanonicalVehicle(
                brand: (string) ($vehicleData['brand'] ?? ''),
                model: (string) ($vehicleData['model'] ?? ''),
                year: isset($vehicleData['year']) ? (int) $vehicleData['year'] : null,
            ),
            new VehicleInsuranceProfile(
                fuelType: $fuel !== null ? VehicleFuelTypeEnum::from((string) $fuel) : null,
                isZeroKm: isset($vehicleData['is_zero_km']) ? (bool) $vehicleData['is_zero_km'] : null,
                estimatedValue: isset($vehicleData['estimated_value']) ? (float) $vehicleData['estimated_value'] : null,
            ),
        ];
    }

    private static function underwritingFrom(array $data): array
    {
        $known = array_column(InsuranceUnderwritingFieldEnum::cases(), 'value');

        $underwriting = array_filter(
            array_intersect_key($data, array_flip($known)),
            fn (mixed $value): bool => $value !== null && $value !== ''
        );

        $birthDate = $underwriting[InsuranceUnderwritingFieldEnum::BIRTH_DATE->value] ?? null;

        if ($birthDate !== null && ! self::isValidDate((string) $birthDate)) {
            throw new ValidationException('birth_date must be a valid date formatted Y-m-d');
        }

        return $underwriting;
    }

    private static function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
