<?php

declare(strict_types=1);

namespace Kanvas\Roles\Enums;

use Baka\Contracts\EnumsInterface;

enum Defaults implements EnumsInterface
{
    case DEFAULT_ACL_COMPANY_ID;
    case DEFAULT_ACL_APP_ID;
    case DEFAULT;
    case DEFAULT_ROLES_NAMES;

    public function getValue(): mixed
    {
        return match ($this) {
            self::DEFAULT_ACL_COMPANY_ID => 1,
                self::DEFAULT_ACL_APP_ID => 1,
                self::DEFAULT => 'Admins',
                self::DEFAULT_ROLES_NAMES => ['Admin', 'Admins', 'User', 'Users', 'Agents'],
        };
    }
}
