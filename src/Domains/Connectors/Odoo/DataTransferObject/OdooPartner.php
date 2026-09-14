<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\DataTransferObject;

use Kanvas\Connectors\Odoo\DataTransferObject\Concerns\FiltersNullFields;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\Data;

/**
 * `res.partner` represents both People and Organizations in Odoo — see `PullPeopleAction`'s
 * docblock for the inbound side of the same mapping.
 */
class OdooPartner extends Data
{
    use FiltersNullFields;

    public function __construct(
        public string $name,
        public bool $is_company,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $parent_id = null,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        return new self(
            name: $organization->name ?: 'Unknown',
            is_company: true,
            phone: $organization->phone,
        );
    }

    public static function fromPeople(People $people, ?Organization $organization = null): self
    {
        $parentOdooId = $organization?->get(CustomFieldEnum::ODOO_ACCOUNT_ID->value);

        return new self(
            name: trim($people->firstname . ' ' . $people->lastname) ?: 'Unknown',
            is_company: false,
            email: $people->getEmails()->first()?->value,
            phone: $people->getPhones()->first()?->value,
            parent_id: $parentOdooId ? (int) $parentOdooId : null,
        );
    }
}
