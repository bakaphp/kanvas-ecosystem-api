<?php

declare(strict_types=1);

namespace Kanvas\Guild\Enums;

enum FlagEnum: string
{
    case COMPANY_LEADS_LIST_BY_PERMISSION = 'flag_company_leads_list_by_permission';
    case COMPANY_ALLOW_DUPLICATED_PEOPLE = 'flag_company_duplicated_people';
    case COMPANY_ALLOW_DUPLICATED_CONTACT_TYPE = 'flag_company_duplicated_contact_type';
    case COMPANY_EMAIL_DOMAIN_VALIDATION = 'email_domain_validation';
    case COMPANY_MULTIPLE_OPEN_LEADS = 'flag_company_multiple_open_leads';
    case COMPANY_CANT_HAVE_MULTIPLE_OPEN_LEADS = 'flag_company_cant_have_multiple_open_leads';
    case COMPANY_MULTIPLE_PEOPLE_OPEN_LEADS = 'flag_company_multiple_people_open_leads';
    case COMPANY_CRM_INTEGRATION_VIN_SOLUTION = 'flag_company_crm_integration_vin_solution';
    case APP_GLOBAL_ZOHO = 'flag_app_global_zoho';
    case ALLOW_NEW_LEAD_FOLLOWER_NOTIFICATIONS = 'allow_new_lead_follower_notifications';
}
