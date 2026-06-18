<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Enums;

enum RevenueGroupByEnum: string
{
    case CUSTOMER = 'customer';
    case MONTH = 'month';
    case ITEM = 'item';
}
