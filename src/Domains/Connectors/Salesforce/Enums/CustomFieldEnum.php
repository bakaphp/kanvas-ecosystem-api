<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Enums;

enum CustomFieldEnum: string
{
    case SALESFORCE_LEAD_ID = 'salesforce_lead_id';
    case SALESFORCE_CONTACT_ID = 'salesforce_contact_id';
    case SALESFORCE_ACCOUNT_ID = 'salesforce_account_id';
    case SALESFORCE_OPPORTUNITY_ID = 'salesforce_opportunity_id';
    case LEAD_FIELDS_MAP = 'salesforce_lead_fields_map';
    case CONTACT_FIELDS_MAP = 'salesforce_contact_fields_map';
    case ACCOUNT_FIELDS_MAP = 'salesforce_account_fields_map';
    case OPPORTUNITY_FIELDS_MAP = 'salesforce_opportunity_fields_map';
}
