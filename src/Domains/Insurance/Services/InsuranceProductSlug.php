<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

class InsuranceProductSlug
{
    public static function for(string $provider, string $productCode): string
    {
        return $provider . '-' . strtolower(str_replace(['_', ' '], '-', $productCode));
    }
}
