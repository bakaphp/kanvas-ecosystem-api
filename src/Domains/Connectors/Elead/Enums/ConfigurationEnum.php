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

    public static function getUserKey(Companies $company, UserInterface $user): string
    {
        return self::USER->value . '_' . $company->getId();
    }
}
