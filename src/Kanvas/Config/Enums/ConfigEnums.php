<?php

declare(strict_types=1);

namespace Kanvas\Config\Enums;

use Baka\Contracts\EnumsInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

enum ConfigEnums implements EnumsInterface
{
    case APPS;
    case COMPANIES;
    case USERS;

    public function getValue(): mixed
    {
        return match ($this) {
            self::APPS => Apps::class,
            self::COMPANIES => Companies::class,
            self::USERS => Users::class
        };
    }

    /**
     * Given the enum name get its value.
     */
    public static function fromName(string $name): mixed
    {
        return constant("self::$name")->getValue();
    }
}
