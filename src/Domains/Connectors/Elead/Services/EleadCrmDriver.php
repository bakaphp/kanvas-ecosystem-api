<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Contracts\CrmLeadDriver;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\SalesAssist\Enums\CrmTypeEnum;

/**
 * eLead only links: it finds the people and lead the page's ids (lPID, lDID) or the customer's name and
 * contacts point at, updates them and stores the ids, and never creates. The eLead pull locks a lead on
 * ELEAD_OPPORTUNITY_ID, which the page does not have, so it and this sync do not exclude each other; with
 * nothing created the worst case is the last write winning on a lead.
 */
class EleadCrmDriver implements CrmLeadDriver
{
    public function type(): CrmTypeEnum
    {
        return CrmTypeEnum::ELEAD;
    }

    public function isConfigured(CompanyInterface $company): bool
    {
        return ! empty($company->get(CustomFieldEnum::COMPANY->value));
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function clientIdField(): string
    {
        return CustomFieldEnum::PERSON_ID->value;
    }

    public function leadIdField(): string
    {
        return CustomFieldEnum::LEAD_ID->value;
    }

    public function resolveStatusName(?string $crmStatus): ?string
    {
        return $crmStatus;
    }

    /**
     * shortcut: the page's salesperson is not read yet, so the owner is never changed; resolve it once its
     * format on the page is known.
     */
    public function resolveOwnerId(?string $salespersonName, Companies $company): ?int
    {
        return null;
    }

    public function customerCustomFields(CrmCustomer $customer): array
    {
        return [];
    }

    public function leadCustomFields(CrmLeadInfo $lead): array
    {
        return [];
    }
}
