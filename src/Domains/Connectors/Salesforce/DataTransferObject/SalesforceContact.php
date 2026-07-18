<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\DataTransferObject;

use Kanvas\Connectors\Salesforce\DataTransferObject\Concerns\MapsAdditionalFields;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\Data;

class SalesforceContact extends Data
{
    use MapsAdditionalFields;

    public function __construct(
        public string $LastName,
        public ?string $FirstName = null,
        public ?string $Email = null,
        public ?string $Phone = null,
        public ?string $AccountId = null,
        public ?array $additionalFields = [],
    ) {
    }

    public static function fromPeople(People $people, ?Organization $organization = null): self
    {
        $company = $people->company;
        $fieldsMap = $company?->get(CustomFieldEnum::CONTACT_FIELDS_MAP->value);

        $accountId = $organization?->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);

        return new self(
            LastName: $people->lastname ?: ($people->firstname ?: 'Unknown'),
            FirstName: $people->firstname,
            Email: $people->getEmails()->first()?->value,
            Phone: $people->getPhones()->first()?->value,
            AccountId: $accountId ? (string) $accountId : null,
            additionalFields: self::mapAdditionalFields($fieldsMap, $people->getAll()),
        );
    }
}
