<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureFeeEnum: string
{
    case DOCUMENTATION_FEE = 'documentation_fee';
    case ELECTRONIC_FILING_CHARGE = 'electronic_filing_charge';
}
