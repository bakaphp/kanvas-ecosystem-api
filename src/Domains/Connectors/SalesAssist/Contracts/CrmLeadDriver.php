<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Contracts;

use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\SalesAssist\Enums\CrmTypeEnum;

/**
 * What differs between CRMs in a lead sync. The tree, the people search, contacts, address,
 * participants and followers are the same for every CRM and live in the sync actions, not here.
 */
interface CrmLeadDriver
{
    public function type(): CrmTypeEnum;

    public function isConfigured(CompanyInterface $company): bool;

    /**
     * Whether the sync may create what it does not find: the people, the lead, a participant's people
     * and lead type, source or status rows. A CRM that cannot only links and updates what already exists.
     */
    public function canCreate(): bool;

    /**
     * Custom field on the people that holds the CRM's customer id. Null for a CRM that only has a
     * lead id, which skips the lookup by client id.
     */
    public function clientIdField(): ?string;

    /**
     * Custom field on the lead that holds the CRM's own lead id.
     */
    public function leadIdField(): string;

    /**
     * The status name the company's catalog and its active-status mapping use for what the CRM calls
     * `$crmStatus`.
     */
    public function resolveStatusName(?string $crmStatus): ?string;

    public function resolveOwnerId(?string $salespersonName, Companies $company): ?int;

    /**
     * @return array<string, mixed> custom fields to set on the people
     */
    public function customerCustomFields(CrmCustomer $customer): array;

    /**
     * @return array<string, mixed> custom fields to set on the lead
     */
    public function leadCustomFields(CrmLeadInfo $lead): array;
}
