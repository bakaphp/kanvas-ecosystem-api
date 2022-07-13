<?php

declare(strict_types=1);

namespace Kanvas\Apps\Apps\Enums;

use Kanvas\Contracts\EnumsInterface;

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
        };
    }
}
