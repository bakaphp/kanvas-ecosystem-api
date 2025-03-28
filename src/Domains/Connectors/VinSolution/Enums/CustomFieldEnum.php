<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Enums;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;

enum CustomFieldEnum: string
{
    case COMPANY = 'VIN_SOLUTION_COMPANY';
    case USER = 'VIN_SOLUTION_USER';
    case LEADS = 'VIN_SOLUTION_LEADS';
    case CONTACT = 'VIN_SOLUTION_CONTACT';
    case LEADS_SOURCE_ID = 'VIN_SOLUTION_LEADS_SOURCE_ID';
    case LEADS_PAGINATION = 'VIN_SOLUTION_LEADS_PAGINATION';
    case OVERDUE_KEYWORD = 'overdue';
    case OVERDUE_TASKS = 'VIN_SOLUTIONS_OVER_DUE_TASKS';
    case CREDIT_APP_SUBMITS = 'VIN_SOLUTIONS_CREDIT_APP_SUBMITS';
    case DEFAULT_STATE = 'FL';
    case DEFAULT_STATE_KEY = 'VIN_SOLUTIONS_DEFAULT_STATE';
    case LEADS_STATUS_ACTIVE = '1';
    case VEHICLE_INFO_KEY = 'vehicle-info';
    case TRADE_IN_INFO_KEY = 'trade-in';
    case PAY_OFF_INFO = 'payoff';
    case DOC_INSURANCE_INFO = 'docs-insurance';
    case DOC_VALID_INFO = 'valid-info';
    case ESIGN_INFO_KEY = 'esign-docs';

    public static function getUserKey(Companies $company, UserInterface $user): string
    {
        return self::USER . '_' . $company->getId() . '_' . $user->getId();
    }
}
