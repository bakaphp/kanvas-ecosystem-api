<?php

declare(strict_types=1);

namespace Baka\Validations;

use Baka\Support\Str;

class Phone
{
    public static function isTollFreeNumber(string $phoneValue): bool
    {
        $tollFreePrefixes = ['800', '888', '877', '866', '855', '844', '833'];

        foreach ($tollFreePrefixes as $prefix) {
            if (Str::contains($phoneValue, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
