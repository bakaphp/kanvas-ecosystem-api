<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

enum StateEnums implements EnumsInterface
{
    case ON;
    case OFF;
    case YES;
    case NO;
    case ON_STRING;
    case YES_STRING;
    case OFF_STRING;
    case NO_STRING;
    case EMPTY_STRING;

    /**
     * Get value.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::ON => 1,
            self::OFF => 0,
            self::YES => 1,
            self::NO => 0,
            self::ON_STRING => 'Y',
            self::YES_STRING => 'Y',
            self::OFF_STRING => 'N',
            self::NO_STRING => 'N',
            self::EMPTY_STRING => '',
        };
    }
}
