<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportParty;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Users\Models\Users;

/**
 * Create-or-find the Guild Organization that Scribe invoices/bills bill to. Acumatica customers and
 * vendors are both BAccount rows, so this mirrors CreateAcumaticaPersonAction — the only difference
 * is the external-id custom-field key (CUSTOMER_ID vs VENDOR_ID). Idempotent on that key.
 */
class CreateAcumaticaOrganizationAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row joined BAccount + Contact + Address row
     */
    public function execute(array $row, bool $isVendor = false): ?Organization
    {
        $party = AcumaticaImportParty::fromRow($row, $isVendor);

        if ($party->sourceId === '') {
            return null;
        }

        $idField = $isVendor ? CustomFieldEnum::VENDOR_ID : CustomFieldEnum::CUSTOMER_ID;

        /** @var Organization|null $existing */
        $existing = Organization::getByCustomField($idField->value, $party->sourceId, $this->company);

        if ($existing !== null) {
            return $existing;
        }

        $address = $party->address;

        $organization = new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: $this->user,
                app: $this->app,
                name: $party->organization ?? $party->firstname,
                email: $party->email,
                phone: $party->phone,
                address: $address?->address,
                city: $address?->city,
                state: $address?->state,
                zip: $address?->zip,
            ),
        )->execute();

        $organization->set($idField->value, $party->sourceId);

        return $organization;
    }
}
