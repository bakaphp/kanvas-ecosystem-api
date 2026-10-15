<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Contracts\CrmLeadDriver;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Guild\Leads\Enums\CrmTypeEnum;

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

    public function resolveOwnerId(?string $salespersonName, CompanyInterface $company): ?int
    {
        $companyModel = $company instanceof Companies ? $company : Companies::getById($company->getId());

        return SalespersonResolver::resolveUserId($salespersonName, $companyModel);
    }

    /**
     * The language, and the consent values stored the way the webhook stores them (Y or N) so the
     * push back to Reynolds reads the same fields. The text opt status has no webhook counterpart and
     * is kept as the CRM wrote it. A value the CRM did not send is not written.
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
                CustomFieldEnum::CONSENT_OPT_OUT->value => self::ynOrNull($consent?->opt_out),
                CustomFieldEnum::CONSENT_OPT_OUT_USE->value => self::ynOrNull($consent?->opt_out_use),
                CustomFieldEnum::TEXT_OPT_STATUS->value => Str::trimToNull($consent?->text_opt_status),
            ],
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    public function requestsDoNotContact(CrmCustomer $customer): bool
    {
        return self::ynOrNull($customer->consent?->opt_out) === 'Y';
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
