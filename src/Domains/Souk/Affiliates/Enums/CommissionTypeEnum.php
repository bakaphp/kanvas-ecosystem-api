<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum CommissionTypeEnum: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
    case HYBRID = 'hybrid';
    case TIERED = 'tiered';
}
