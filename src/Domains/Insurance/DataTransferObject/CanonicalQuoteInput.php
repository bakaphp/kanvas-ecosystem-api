<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\DateHelper;
use Baka\Support\Str;
use DateTimeImmutable;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\InsuranceUnderwritingFieldEnum;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Inventory\Variants\Models\Variants;

class CanonicalQuoteInput
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly CanonicalVehicle $vehicle,
        public readonly VehicleInsuranceProfile $profile,
        public readonly InsuranceCoverageCategoryEnum $category,
        public readonly array $underwriting = [],
        public readonly ?int $vehicleVariantId = null,
    ) {
    }

    public static function fromMultiple(AppInterface $app, CompanyInterface $company, array $data): self
    {
        $vehicleData = (array) ($data['vehicle'] ?? []);
        [$vehicle, $profile] = isset($vehicleData['variant_id'])
            ? self::fromVariantId((int) $vehicleData['variant_id'], $app, $company)
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
            vehicleVariantId: isset($vehicleData['variant_id']) ? (int) $vehicleData['variant_id'] : null,
        );
    }

    public function underwritingValue(InsuranceUnderwritingFieldEnum $field): ?string
    {
        $value = $this->underwriting[$field->value] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function birthDate(): ?DateTimeImmutable
    {
        $value = $this->underwritingValue(InsuranceUnderwritingFieldEnum::BIRTH_DATE);

        return $value === null ? null : self::parseDate($value);
    }

    public function signature(): string
    {
        $underwriting = $this->underwriting;
        unset($underwriting[InsuranceUnderwritingFieldEnum::IP_ADDRESS->value]);
        ksort($underwriting);

        return md5((string) json_encode([
            $this->vehicle->brandKey(),
            $this->vehicle->modelKey(),
            $this->vehicle->versionKey(),
            $this->vehicle->year,
            $this->profile->fuelType?->value,
            $this->profile->isZeroKm,
            $this->profile->estimatedValue,
            $this->category->value,
            $underwriting,
        ]));
    }

    private static function fromVariantId(int $variantId, AppInterface $app, CompanyInterface $company): array
    {
        $product = Variants::getByIdFromCompanyApp($variantId, $company, $app)->product;

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
                version: Str::trimToNull(isset($vehicleData['version']) ? (string) $vehicleData['version'] : null),
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

        if ($birthDate !== null && self::parseDate((string) $birthDate) === null) {
            throw new ValidationException('birth_date must be a valid date formatted Y-m-d');
        }

        return $underwriting;
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        return DateHelper::parseYmd($value);
    }
}
