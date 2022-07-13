<?php

namespace Kanvas\Enums;

use Kanvas\Contracts\EnumsInterface;

enum HttpDefaults implements EnumsInterface
{
    case RECORDS_PER_PAGE;

    public function getValue(): mixed
    {
        return match ($this) {
            self::RECORDS_PER_PAGE => 25,
        };
    }
}
