<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Enums;

/**
 * Gates the Salesforce push specifically — independent of PeopleApprovalTypeEnum::CONTENT, the plain
 * Kanvas content review. RequestPeopleApprovalActivity picks between the two cases by whether the
 * People already has a Salesforce Contact id.
 */
enum PeopleSalesforceSyncApprovalTypeEnum: string
{
    case CREATE = 'approve_people_salesforce_create';
    case UPDATE = 'approve_people_salesforce_update';
}
