<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\DataTransferObject;

use Carbon\Carbon;
use Exception;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
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

        $additionalFields = [];
        if ($companyZohoMapFields && is_array($companyZohoMapFields)) {
            self::mapProperties(
                $companyZohoMapFields,
                $additionalFields,
                $customFields
            );
        }

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

    public function toArray(): array
    {
        $data = array_merge(parent::toArray(), $this->additionalFields);
        unset($data['additionalFields']);

        // Remove empty values
        return array_filter($data, fn ($value) => ! empty($value));
    }

    /**
     * Map properties from one array to another.
     */
    protected static function mapProperties(array $map, array &$data, array $entity): void
    {
        /**
         * map = [
         * 'Affiliate_sOMETHING' => 'ZohoKey'
         * ].
         */
        foreach ($map as $key => $name) {
            $value = $entity[$key] ?? null;
            if (is_array($name)) {
                if ($name['type'] !== 'date') {
                    settype($value, $name['type']);
                } else {
                    try {
                        $date = Carbon::parse($value);
                        $value = $date->format('Y-m-d');
                    } catch (Exception $e) {
                        $value = null;
                    }
                }
                $name = $name['name'];
            }
            if (strtolower($key) == 'credit_score' && $value != null) {
                $creditScore = [
                    1 => '720-950',
                    2 => '680-719',
                    3 => '640-679',
                    4 => '639 or less',
                ];

                $value = $creditScore[(int) $value] ?? $value;
            }

            if ($value !== null) {
                $data[$name] = $value;
            }
        }
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
