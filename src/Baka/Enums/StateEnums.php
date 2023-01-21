<?php
declare(strict_types=1);

namespace Baka\Enums;

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
    case DEFAULT_PARENT_ID;
    case DEFAULT_POSITION;
    case PUBLISHED;
    case UN_PUBLISHED;
    case IS_DEFAULT;
    case DEFAULT;
    case DEFAULT_NAME;
    case DEFAULT_NAME_SLUG;

    /**
     * Get value.
     *
     * @return mixed
     */
    public function getValue() : mixed
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
            self::DEFAULT_PARENT_ID => 0,
            self::DEFAULT_POSITION => 0,
            self::PUBLISHED => 1,
            self::UN_PUBLISHED => 0,
            self::IS_DEFAULT => 0,
            self::DEFAULT => 1,
            self::DEFAULT_NAME => 'Default',
            self::DEFAULT_NAME_SLUG => 'default',
        };
    }
}
