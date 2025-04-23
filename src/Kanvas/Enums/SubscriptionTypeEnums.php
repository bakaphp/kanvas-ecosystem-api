<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

enum SubscriptionTypeEnums implements EnumsInterface
{
    case GROUP;
    case COMPANY;
    case BRANCH;

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::GROUP   => 1,
            self::COMPANY => 2,
            self::BRANCH  => 3,
        };
    }
}
