<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts\Enums;

use Kanvas\Connectors\Intras\Enums\CustomFieldEnum as IntrasCustomFieldEnum;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum as SalesforceCustomFieldEnum;

/**
 * One case per connector that sets a third-party external id custom field on Organization. Add a
 * case (and wire it into fieldName()) when a new connector starts doing the same.
 */
enum ThirdPartyOrganizationIdFieldEnum
{
    case SALESFORCE_ACCOUNT_ID;
    case INTRAS_COMPANY_ID;

    public function fieldName(): string
    {
        return match ($this) {
            self::SALESFORCE_ACCOUNT_ID => SalesforceCustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
            self::INTRAS_COMPANY_ID => IntrasCustomFieldEnum::INTRAS_COMPANY_ID->value,
        };
    }

    /**
     * @return list<string>
     */
    public static function fieldNames(): array
    {
        return array_map(fn (self $case) => $case->fieldName(), self::cases());
    }
}
