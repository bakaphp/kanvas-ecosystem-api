<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\DataTransferObject;

use Baka\Validations\Date;
use Kanvas\Connectors\Zoho\Enums\CustomField;
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
        $companyZohoMapFields = $lead->company()->first()->get(CustomField::FIELDS_MAP->value);

        $additionalFields = [];
        if ($companyZohoMapFields && is_array($companyZohoMapFields)) {
            self::mapProperties(
                $companyZohoMapFields,
                $additionalFields,
                $customFields
            );
        }

        $people = $lead->people()->first();

        return new self(
            $people->firstname,
            $people->lastname,
            $people->getEmails()->first()?->value,
            $people->getPhones()->first()?->value,
            $lead->description,
            (string) ($lead->owner()->first()->get(CustomField::ZOHO_USER_OWNER_ID->value) ?? $lead->company()->first()->get(CustomField::DEFAULT_OWNER->value)),
            (string) ($lead->status()->first()->get(CustomField::ZOHO_STATUS_NAME->value) ?? 'New Lead'),
            $additionalFields
        );
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
                    $value = Date::isValid($value, 'm/d/Y') || Date::isValid($value, 'Y-m-d') || Date::isValid($value, 'd-m-Y') ? date('Y-m-d', strtotime($value)) : null;
                }
                $name = $name['name'];
            }

            $data[$name] = $value;
        }
    }
}
