<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Enums;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;

enum ConfigurationEnum: string
{
    case CLIENT_ID = 'VINSOLUTIONS_CLIENT_ID';
    case CLIENT_SECRET = 'VINSOLUTIONS_CLIENT_SECRET';
    case API_KEY = 'VINSOLUTIONS_API_KEY';
    case API_KEY_DIGITAL_SHOWROOM = 'VINSOLUTIONS_API_KEY_DIGITAL_SHOWROOM';
    case COMPANY = 'VIN_SOLUTION_COMPANY';
    case USER = 'VIN_SOLUTION_USER';
    case LEADS = 'VIN_SOLUTION_LEADS';
    case CONTACT = 'VIN_SOLUTION_CONTACT';
    case LEADS_SOURCE_ID = 'VIN_SOLUTION_LEADS_SOURCE_ID';
    case LEADS_PAGINATION = 'VIN_SOLUTION_LEADS_PAGINATION';
    case OVERDUE_TASKS = 'VIN_SOLUTIONS_OVER_DUE_TASKS';
    case CREDIT_APP_SUBMITS = 'VIN_SOLUTIONS_CREDIT_APP_SUBMITS';
    case DEFAULT_STATE_KEY = 'VIN_SOLUTIONS_DEFAULT_STATE';
    case LEAD_TIME_DIFF_MINUTES = 'VIN_LEAD_TIME_DIFF_MINUTES';
    case MANAGER_NOTIFIED_AT = 'VIN_SOLUTION_MANAGER_NOTIFIED_AT';
    case DOWNLOAD_ALL_LEADS_USER = 'VIN_SOLUTION_DOWNLOAD_ALL_LEADS_USER';
    case SESSION_COOKIES = 'VIN_SOLUTION_SESSION_COOKIES';
    case APPOINTMENT_DURATION_MINUTES = 'VIN_SOLUTION_APPOINTMENT_DURATION_MINUTES';

    public static function getUserKey(Companies $company, UserInterface $user): string
    {
        return self::USER->value . '_' . $company->getId() . '_' . $user->getId();
    }

    public static function appointmentDurationMinutes(Companies $company): int
    {
        return (int) ($company->get(self::APPOINTMENT_DURATION_MINUTES->value) ?? 30);
    }
}
