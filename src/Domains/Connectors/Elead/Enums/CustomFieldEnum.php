<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Enums;

use Kanvas\Companies\Models\Companies;

enum CustomFieldEnum: string
{
    case COMPANY = 'ELEADS_SUBSCRIPTION_ID';
    case USER = 'ELEADS_SOLUTION_USER';
    case CUSTOMER_ID = 'ELEAD_CUSTOMER_ID'; // api uuid
    case PERSON_ID = 'ELEAD_PERSON_ID'; //popup id
    case OPPORTUNITY_ID = 'ELEAD_OPPORTUNITY_ID'; //api uui
    case OPPORTUNITY_POPUP_UUID = 'ELEAD_OPPORTUNITY_POPUP_UUID';
    case LEAD_ID = 'ELEAD_LEAD_ID'; //popup id
    case VEHICLE_SOUGHT_ID = 'ELEAD_VEHICLE_SOUGHT_ID';
    case VEHICLE_TRADE_IN_ID = 'ELEAD_VEHICLE_TRADE_IN_ID';
    case LEAD_SOURCE_ID = 'ELEAD_LEAD_SOURCE_ID';
    case LEAD_SUB_STATUS = 'ELEAD_LEAD_SUB_STATUS';
    case IN_SHOW_ROOM = 'In Showroom';
    case ELEAD_USER_POSITION_CODE = 'eLeadsJobPositionCode';
    case CREDIT_APP_IMPORTER = 'credit_app_imported';
    case GET_DOCS_IMPORTER = 'get_docs_imported';
    case CO_BUYER_APP_IMPORTER = 'co_buyer_imported';
    case ID_VERIFICATION = 'id_verification';

    /**
     * Get the user Key.
     */
    public static function getUserKey(Companies $company): string
    {
        return self::USER->value . '_' . $company->getId();
    }

    public static function getUserJobPositionKey(Companies $company): string
    {
        return self::ELEAD_USER_POSITION_CODE->value . '_' . $company->getId();
    }
}
