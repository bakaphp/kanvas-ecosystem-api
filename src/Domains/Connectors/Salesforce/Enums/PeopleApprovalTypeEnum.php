<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Enums;

/**
 * The two independent approval gates a Lead's People goes through before it reaches Salesforce —
 * neither is a prerequisite of the other. CONTENT reviews the People in Kanvas; SALESFORCE_CREATE /
 * SALESFORCE_UPDATE specifically gates the Salesforce push (RequestPeopleApprovalActivity picks
 * between the two by whether the People already has a Salesforce Contact id).
 */
enum PeopleApprovalTypeEnum: string
{
    case CONTENT = 'approve_people';
    case SALESFORCE_CREATE = 'approve_people_salesforce_create';
    case SALESFORCE_UPDATE = 'approve_people_salesforce_update';
}
