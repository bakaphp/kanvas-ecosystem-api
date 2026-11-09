<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

use Kanvas\Connectors\Stripe\Providers\StripeTaxProvider;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\Providers\ManualRateTaxProvider;

enum TaxProviderEnum: string
{
    case MANUAL = 'manual';
    case STRIPE_TAX = 'stripe_tax';
    case NONE = 'none';

    public static function of(TaxProviderInterface $provider): self
    {
        return match (true) {
            $provider instanceof ManualRateTaxProvider => self::MANUAL,
            $provider instanceof StripeTaxProvider => self::STRIPE_TAX,
            default => self::NONE,
        };
    }
}
