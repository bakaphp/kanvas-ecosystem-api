<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum PayoutFrequencyEnum: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
}
