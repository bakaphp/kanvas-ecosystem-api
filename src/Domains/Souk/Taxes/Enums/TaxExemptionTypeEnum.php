<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxExemptionTypeEnum: string
{
    case RESALE = 'resale';
    case GOVERNMENT = 'government';
    case NONPROFIT = 'nonprofit';
    case DIPLOMATIC = 'diplomatic';
    case MEDICAL = 'medical';
}
