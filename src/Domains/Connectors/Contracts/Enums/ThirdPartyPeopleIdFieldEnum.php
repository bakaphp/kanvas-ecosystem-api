<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts\Enums;

use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum as SalesforceCustomFieldEnum;

/**
 * One case per connector using SyncPeopleByThirdPartyCustomFieldAction to create/match People by
 * external id. Add a case (and wire it into fieldName()) when a new connector adopts that action.
 */
enum ThirdPartyPeopleIdFieldEnum
{
    case SALESFORCE_CONTACT_ID;
    case DEALER_SOCKET_CUSTOMER_ID;
    case ELEAD_PERSON_ID;
    case ELEAD_CUSTOMER_ID;

    public function fieldName(): string
    {
        return match ($this) {
            self::SALESFORCE_CONTACT_ID => SalesforceCustomFieldEnum::SALESFORCE_CONTACT_ID->value,
            self::DEALER_SOCKET_CUSTOMER_ID => DealerSocketCustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value,
            self::ELEAD_PERSON_ID => EleadCustomFieldEnum::PERSON_ID->value,
            self::ELEAD_CUSTOMER_ID => EleadCustomFieldEnum::CUSTOMER_ID->value,
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
