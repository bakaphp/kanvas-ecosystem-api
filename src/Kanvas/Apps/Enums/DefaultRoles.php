<?php

declare(strict_types=1);

namespace Kanvas\Apps\Enums;

use Kanvas\Contracts\EnumsInterface;

enum DefaultRoles implements EnumsInterface
{
    case ADMIN;
    case USER;
    case AGENT;
    case MANAGER;

    public function getValue() : mixed
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::USER => 'Users',
            self::AGENT => 'Agents',
            self::MANAGER => 'Manager',
        };
    }
}
