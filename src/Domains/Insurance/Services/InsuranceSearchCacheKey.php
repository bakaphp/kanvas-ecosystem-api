<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

class InsuranceSearchCacheKey
{
    public static function make(
        int $appId,
        int $companyId,
        int $planId,
        string $vehicleSignature
    ): string {
        return sprintf('insuranceSearch-%d-%d-%d-%s', $appId, $companyId, $planId, $vehicleSignature);
    }
}
