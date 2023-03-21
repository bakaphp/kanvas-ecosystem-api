<?php

declare(strict_types=1);

namespace Kanvas\Apps\Enums;

use Baka\Contracts\EnumsInterface;

enum DefaultRoles implements EnumsInterface
{
    case ADMIN;
    case USER;
    case AGENT;
    case MANAGER;
    case DEVELOPER;

    public function getValue(): mixed
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::USER => 'Users',
            self::AGENT => 'Agents',
            self::MANAGER => 'Managers',
            self::DEVELOPER => 'Developer',
        };
    }
}
