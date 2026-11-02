<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Illuminate\Support\Str;

class VehicleKeyNormalizer
{
    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }
}
