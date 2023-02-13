<?php

declare(strict_types=1);

namespace Kanvas\Apps\Enums;

use Baka\Contracts\EnumsInterface;

/**
 * @deprecated version 1.0 use Global Enums AppEnum
 */
enum Defaults implements EnumsInterface
{
    case CORE_APP_ID;
    case ECOSYSTEM_APP_ID;
    case GLOBAL_APP_ID;
    case GLOBAL_COMPANY_ID;
    case ECOSYSTEM_COMPANY_ID;
    case DEFAULT_APP_NAME;
    case DEFAULT_ROLE_SETTING;
    case DEFAULT_COUNTRY;
    case VERSION;
    case DEFAULT_SEX;
    case DEFAULT_TIMEZONE;
    case DEFAULT_USER_LEVEL;
    case DEFAULT_LANGUAGE;
    case DEFAULT_ROLE_ID;

    public function getValue(): mixed
    {
        return match ($this) {
            self::CORE_APP_ID => 1,
            self::ECOSYSTEM_APP_ID => 1,
            self::GLOBAL_APP_ID => 10,
            self::GLOBAL_COMPANY_ID => 0,
            self::ECOSYSTEM_COMPANY_ID => 1,
            self::DEFAULT_APP_NAME => 'Default',
            self::DEFAULT_ROLE_SETTING => 'default_admin_role',
            self::DEFAULT_COUNTRY => 'default_user_country',
            self::VERSION => 0.3,
            self::DEFAULT_SEX => 'U',
            self::DEFAULT_TIMEZONE => 'America/New_York',
            self::DEFAULT_USER_LEVEL => 3,
            self::DEFAULT_LANGUAGE => 'EN',
            self::DEFAULT_ROLE_ID => 2,
        };
    }
}
