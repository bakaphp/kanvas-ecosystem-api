<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * Webhook events send these upper case, the REST API lower case.
 */
enum RevenueCatPeriodTypeEnum: string
{
    case TRIAL = 'TRIAL';
    case INTRO = 'INTRO';
    case NORMAL = 'NORMAL';
    case PROMOTIONAL = 'PROMOTIONAL';
    case PREPAID = 'PREPAID';

    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtoupper($value)) : null;
    }
}
