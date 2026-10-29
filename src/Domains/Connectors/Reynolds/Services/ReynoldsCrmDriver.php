<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Contracts\CrmLeadDriver;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CrmTypeEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;

class ReynoldsCrmDriver implements CrmLeadDriver
{
    use ScalarCoercionTrait;

    public function type(): CrmTypeEnum
    {
        return CrmTypeEnum::REYNOLDS;
    }

    public function isConfigured(CompanyInterface $company): bool
    {
        return $company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value) !== null;
    }

    public function clientIdField(): string
    {
        return CustomFieldEnum::CLIENT_ID->value;
    }

    public function leadIdField(): string
    {
        return CustomFieldEnum::PROSPECT_ID->value;
    }

    /**
     * R&R calls a live prospect "Open"; the catalog and the company's active-status mapping call it "Active".
     */
    public function resolveStatusName(?string $crmStatus): ?string
    {
        return $crmStatus === 'Open' ? 'Active' : $crmStatus;
    }

    public function resolveOwnerId(?string $salespersonName, Companies $company): ?int
    {
        return SalespersonResolver::resolveUserId($salespersonName, $company);
    }

    /**
     * The language, and the dealer follow-up flags stored the way the webhook stores them (Y or N) so
     * the push back to Reynolds reads the same fields. The CRM's opt-out flags are not stored: they are
     * its data-sharing choices, not a request to stop contact. A value the CRM did not send is not
     * written.
     */
    public function customerCustomFields(CrmCustomer $customer): array
    {
        $consent = $customer->consent;

        return array_filter(
            [
                CustomFieldEnum::LANGUAGE->value => $customer->language,
                CustomFieldEnum::CONSENT_PHONE->value => self::ynOrNull($consent?->follow_up?->phone),
                CustomFieldEnum::CONSENT_MAIL->value => self::ynOrNull($consent?->follow_up?->mail),
                CustomFieldEnum::CONSENT_EMAIL->value => self::ynOrNull($consent?->follow_up?->email),
                CustomFieldEnum::CONSENT_TEXT->value => self::ynOrNull($consent?->follow_up?->text),
            ],
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * The type and status names the webhook keeps on a lead it creates, so a lead made here looks the
     * same to everything that reads them. The CRM's source is not copied into REYNOLDS_PROVIDER_NAME: the
     * webhook fills that from a provider vocabulary the CRM's source names do not follow.
     */
    public function leadCustomFields(CrmLeadInfo $lead): array
    {
        return array_filter(
            [
                CustomFieldEnum::PROSPECT_TYPE->value => $lead->type?->name,
                CustomFieldEnum::PROSPECT_STATUS_TYPE->value => $lead->status,
            ],
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
