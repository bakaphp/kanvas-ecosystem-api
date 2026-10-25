<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxTreatmentEnum: string
{
    case TAXED = 'taxed';
    case EXEMPT = 'exempt';
    case ZERO_RATED = 'zero_rated';
    case REVERSE_CHARGE = 'reverse_charge';
    case NOT_COLLECTING = 'not_collecting';
    case NO_BASE = 'no_base';
}
