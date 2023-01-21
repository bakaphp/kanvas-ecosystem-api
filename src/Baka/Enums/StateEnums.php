<?php
declare(strict_types=1);

namespace Baka\Enums;

use Baka\Contracts\EnumsInterface;

enum StateEnums implements EnumsInterface
{
    case DEFAULT_PARENT_ID;
    case DEFAULT_POSITION;
    case PUBLISHED;
    case UN_PUBLISHED;
    case IS_DEFAULT;
    case DEFAULT;
    case DEFAULT_NAME;
    case DEFAULT_NAME_SLUG;

    /**
     * Get value
     *
     * @return mixed
     */
    public function getValue() : mixed
    {
        return match ($this) {
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
