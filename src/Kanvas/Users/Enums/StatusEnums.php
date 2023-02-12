<?php

declare(strict_types=1);

namespace Kanvas\Users\Enums;

use Baka\Contracts\EnumsInterface;

enum StatusEnums implements EnumsInterface
{
    case ACTIVE;
    case INACTIVE;
    case INVITED;
    case ANONYMOUS;

    public function getValue(): mixed
    {
        return match ($this) {
            self::ANONYMOUS => -1,
            self::ACTIVE => 1,
            self::INACTIVE => 0,
            self::INVITED => 2
        };
    }
}
