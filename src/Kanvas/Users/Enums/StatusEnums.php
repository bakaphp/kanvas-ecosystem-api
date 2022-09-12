<?php

namespace Kanvas\Users\Enums;

use Kanvas\Contracts\EnumsInterface;

enum StatusEnums implements EnumsInterface
{
    case ACTIVE;
    case INACTIVE;
    case INVITED;

    public function getValue(): mixed
    {
        return match ($this) {
            self::ACTIVE => 1,
            self::INACTIVE => 0,
            self::INVITED => 2
        };
    }
}
