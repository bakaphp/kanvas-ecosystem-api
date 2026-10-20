<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Illuminate\Support\Str;

class InsuranceRequestId
{
    public static function for(string $reference): string
    {
        return $reference . '-' . Str::lower(Str::random(8));
    }
}
