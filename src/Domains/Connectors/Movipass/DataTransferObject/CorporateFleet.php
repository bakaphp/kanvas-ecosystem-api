<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\DataTransferObject;

use Spatie\LaravelData\Data;

class CorporateFleet extends Data
{
    /**
     * @param FleetVehicle[] $vehicles
     */
    public function __construct(
        public readonly string $legalName,
        public readonly string $rnc,
        public readonly array $vehicles,
        public readonly ?string $commercialName = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactPhone = null,
    ) {
    }

    /**
     * Build the DTO from a decoded fleet JSON. Company metadata may live under a
     * `company` key or at the top level; vehicles under `vehicles`.
     */
    public static function fromImportArray(array $data): self
    {
        $company = $data['company'] ?? $data;
        $vehicles = $data['vehicles'] ?? [];

        return new self(
            legalName: trim((string) ($company['legal_name'] ?? '')),
            rnc: trim((string) ($company['rnc'] ?? '')),
            vehicles: array_map(
                static fn (array $row) => FleetVehicle::fromImportArray($row),
                array_values($vehicles),
            ),
            commercialName: self::stringOrNull($company['commercial_name'] ?? null),
            contactName: self::stringOrNull($company['contact_name'] ?? null),
            contactEmail: self::stringOrNull($company['contact_email'] ?? $company['contact_user_email'] ?? null),
            contactPhone: self::stringOrNull($company['contact_phone'] ?? null),
        );
    }

    public function companyName(): string
    {
        $name = trim((string) ($this->commercialName ?: $this->legalName));

        return $name !== '' ? $name : 'Corporate Account';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
