<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxProviderEnum: string
{
    case MANUAL = 'manual';
    case STRIPE_TAX = 'stripe_tax';
    case NONE = 'none';
}
