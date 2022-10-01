<?php
declare(strict_types=1);

namespace Kanvas\Enums;

use Kanvas\Contracts\EnumsInterface;

enum AppEnums implements EnumsInterface
{
    case DEFAULT_TIMEZONE;
    case DEFAULT_SEX;
    case DEFAULT_NAME;
    case DEFAULT_LANGUAGE;
    case DEFAULT_ROLE_ID;
    case VERSION;
    case GLOBAL_APP_ID;
    case ECOSYSTEM_APP_ID;
    case GLOBAL_COMPANY_ID;
    case DEFAULT_ROLE_NAME;
    case DEFAULT_ROLES_NAMES;
    case CORE_APP_ID;
    case ECOSYSTEM_COMPANY_ID;
    case DEFAULT_APP_NAME;
    case DEFAULT_ROLE_SETTING;
    case DEFAULT_COUNTRY;
    case DEFAULT_USER_LEVEL;

    /**
     * Get value
     *
     * @return mixed
     */
    public function getValue() : mixed
    {
        return match ($this) {
            self::DEFAULT_TIMEZONE => 'America/New_York',
            self::DEFAULT_LANGUAGE => 'EN',
            self::DEFAULT_NAME => 'Default',
            self::DEFAULT_SEX => 'U',
            self::VERSION => config('kanvas.app.version'),
            self::GLOBAL_APP_ID => 1,
            self::ECOSYSTEM_APP_ID => 1,
            self::GLOBAL_COMPANY_ID => 0,
            self::DEFAULT_ROLE_NAME => 'Admins',
            self::DEFAULT_ROLES_NAMES => ['Admin', 'Admins', 'User', 'Users', 'Agents'],
            self::ECOSYSTEM_COMPANY_ID => 1,
            self::DEFAULT_APP_NAME => 'Default',
            self::DEFAULT_ROLE_SETTING => 'default_admin_role',
            self::DEFAULT_COUNTRY => 'default_user_country',
            self::DEFAULT_USER_LEVEL => 3,
            self::DEFAULT_ROLE_ID => 2,
        };
    }
}
