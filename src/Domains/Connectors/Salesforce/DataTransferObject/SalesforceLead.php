<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\DataTransferObject;

use Kanvas\Connectors\Salesforce\DataTransferObject\Concerns\MapsAdditionalFields;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Spatie\LaravelData\Data;

class SalesforceLead extends Data
{
    use MapsAdditionalFields;

    public function __construct(
        public string $LastName,
        public string $Company,
        public ?string $FirstName = null,
        public ?string $Email = null,
        public ?string $Phone = null,
        public ?string $Status = null,
        public ?string $Description = null,
        public ?array $additionalFields = [],
    ) {
    }

    public static function fromLead(Lead $lead): self
    {
        $people = $lead->people;
        $company = $lead->company;
        $fieldsMap = $company?->get(CustomFieldEnum::LEAD_FIELDS_MAP->value);

        // Salesforce Lead requires a Company text field even though Kanvas leads aren't always
        // linked to an Organization — fall back to the tenant's own name so create() never 400s.
        $organizationName = $lead->organization?->name ?? $company?->name ?? 'Unknown';

        return new self(
            LastName: $people?->lastname ?: ($people?->firstname ?: 'Unknown'),
            Company: $organizationName,
            FirstName: $people?->firstname,
            Email: $people?->getEmails()->first()?->value,
            Phone: $people?->getPhones()->first()?->value,
            Status: $lead->status()->first()?->name,
            Description: $lead->description,
            additionalFields: self::mapAdditionalFields($fieldsMap, $lead->getAll()),
        );
    }
}
