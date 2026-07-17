<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\DataTransferObject;

use Kanvas\Connectors\Salesforce\DataTransferObject\Concerns\MapsAdditionalFields;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\Data;

class SalesforceAccount extends Data
{
    use MapsAdditionalFields;

    public function __construct(
        public string $Name,
        public ?string $Phone = null,
        public ?int $NumberOfEmployees = null,
        public ?array $additionalFields = [],
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        $fieldsMap = $organization->company?->get(CustomFieldEnum::ACCOUNT_FIELDS_MAP->value);

        return new self(
            Name: $organization->name ?: 'Unknown',
            Phone: $organization->phone,
            NumberOfEmployees: $organization->total_employees ?? null,
            additionalFields: self::mapAdditionalFields($fieldsMap, $organization->getAll()),
        );
    }
}
