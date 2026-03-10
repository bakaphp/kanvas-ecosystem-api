<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Enums;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;

enum ConfigurationEnum: string
{
    case ELEAD_API_KEY = 'ELEAD_API_KEY';
    case ELEAD_API_SECRET = 'ELEAD_API_SECRET';
    case ELEAD_DEV_MODE = 'ELEAD_DEV_MODE';
    case COMPANY = 'ELEAD_COMPANY';
    case USER = 'ELEADS_SOLUTION_USER';
    case ELEAD_DAILY_LIMIT = 'ELEAD_DAILY_LIMIT';
    case ELEAD_ALERT_THRESHOLD = 'ELEAD_ALERT_THRESHOLD';
    case ELEAD_CACHE_TTL = 'ELEAD_CACHE_TTL';
    case ELEAD_REF_CACHE_TTL = 'ELEAD_REF_CACHE_TTL';
    case ELEAD_DEBOUNCE_SECONDS = 'ELEAD_DEBOUNCE_SECONDS';
    case ELEAD_SLACK_CHANNEL = 'ELEAD_SLACK_CHANNEL';

    public static function getUserKey(Companies $company, UserInterface $user): string
    {
        return self::USER->value . '_' . $company->getId();
    }
}
