<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

enum OrderStatsExcludeModeEnum: string
{
    // Neutralize an order only when its excluded transition falls inside the queried period
    // (historically-correct turnover: an entry + same-period cancel net to zero).
    case IN_RANGE = 'in_range';

    // Neutralize an order whenever its current status is excluded, regardless of when it changed
    // (live snapshot: cancelled orders never show up in turnover, even retroactively).
    case CURRENT = 'current';
}
