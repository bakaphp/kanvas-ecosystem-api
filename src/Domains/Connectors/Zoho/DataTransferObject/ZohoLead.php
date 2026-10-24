<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Guild\Leads\Models\Lead;
use Override;
use Spatie\LaravelData\Data;

class ZohoLead extends Data
{
    /**
     * Checkbox on the Zoho lead layout the funding team filters on. Zoho receives the files as
     * attachments, but a filter needs a field; it is only ever set, never cleared.
     */
    public const string HAS_ATTACHMENT_FIELD = 'Has_Attachment';

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

    /**
     * @todo Read the person's default address first and emit Street/City/State/Zip_Code from it,
     *       falling back to the mapped custom fields only when the person has none (docs/TODO.md).
     */
    public static function fromLead(Lead $lead): self
    {
        $customFields = $lead->getAll();
        $company = $lead->company()->first();
        $companyZohoMapFields = $company->get(CustomFieldEnum::FIELDS_MAP->value);

        $additionalFields = is_array($companyZohoMapFields)
            ? ZohoFieldMapService::map($companyZohoMapFields, $customFields)
            : [];

        if (self::hasAttachments($lead)) {
            $additionalFields[self::HAS_ATTACHMENT_FIELD] = true;
        }

        $people = $lead->people()->first();
        $leadStatus = $lead->status()->first();
        // Only the owner's own Zoho user. The company default is a create-time fallback that lives in
        // SyncLeadToZohoAction; from here it would reach every update and move the Zoho owner.
        $owner = Str::trimToNull((string) ($lead->owner?->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value) ?? ''));

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

        return array_filter($data, fn ($value) => ! empty($value));
    }

    /**
     * Files attached in Kanvas, or the receiver form's comma-separated `Attachments` URL list that
     * SyncLeadToZohoAction uploads alongside them.
     */
    public static function hasAttachments(Lead $lead): bool
    {
        return $lead->files()->exists() || Str::trimToNull((string) $lead->get('Attachments')) !== null;
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
