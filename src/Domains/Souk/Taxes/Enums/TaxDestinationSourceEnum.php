<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxDestinationSourceEnum: string
{
    case SHIPPING = 'shipping';
    case BILLING = 'billing';
    case PICKUP_BRANCH = 'pickup_branch';
}
