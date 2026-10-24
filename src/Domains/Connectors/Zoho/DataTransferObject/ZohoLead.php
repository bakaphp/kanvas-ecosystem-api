<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\DataTransferObject;

use Illuminate\Support\Str;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Guild\Leads\Models\Lead;
use Override;
use Spatie\LaravelData\Data;

class ZohoLead extends Data
{
    public function __construct(
        public string $First_Name,
        public string $Last_Name,
        public ?string $Phone = null,
        public ?string $Email = null,
        public ?string $Owner = null,
        public ?string $Description = null,
        public ?string $Lead_Status = null,
        public ?array $additionalFields = []
    ) {
    }

    public static function fromLead(Lead $lead): self
    {
        $customFields = $lead->getAll();
        $company = $lead->company()->first();
        $companyZohoMapFields = $company->get(CustomFieldEnum::FIELDS_MAP->value);

        $additionalFields = is_array($companyZohoMapFields)
            ? ZohoFieldMapService::map($companyZohoMapFields, $customFields)
            : [];

        $people = $lead->people()->first();
        $leadStatus = $lead->status()->first();
        $owner = (string) ($lead->owner()->first() ? $company->get(CustomFieldEnum::DEFAULT_OWNER->value) : null);

        //@todo get the lead status from zoho
        $newLead = 'New Lead';
        $status = $leadStatus ? ($leadStatus->get(CustomFieldEnum::ZOHO_STATUS_NAME->value) ?? $newLead) : $newLead;

        return new self(
            $people->firstname,
            $people->lastname,
            $people->getPhones()->first()?->value,
            $people->getEmails()->first()?->value,
            $owner,
            $lead->description,
            $status,
            $additionalFields
        );
    }

    #[Override]
    public function toArray(): array
    {
        $data = array_merge(parent::toArray(), $this->additionalFields);
        unset($data['additionalFields']);

        if (isset($data['URL']) && is_string($data['URL'])) {
            $data['URL'] = Str::limit($data['URL'], 100, '');
        }

        // Remove empty values
        return array_filter($data, fn ($value) => ! empty($value));
    }

    public function hasMemberNumber(): bool
    {
        return $this->additionalFields['Member_ID'] ?? ($this->additionalFields['Member'] ?? false);
    }

    public function getMemberNumber(): ?string
    {
        return $this->additionalFields['Member_ID'] ?? ($this->additionalFields['Member'] ?? null);
    }
}
