<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

use Baka\Support\Str;

enum ProductTypeEnum: string
{
    case GLOBAL = 'global';
    case LOCAL = 'local';
    case REGIONAL = 'regional';

    public static function getTypeByName(string $name): self
    {
        //if string has the word regional
        if (Str::contains($name, 'regional', true)) {
            return self::REGIONAL;
        }

        if (Str::contains($name, 'global', true)) {
            return self::GLOBAL;
        }

        return self::LOCAL;
    }
}
