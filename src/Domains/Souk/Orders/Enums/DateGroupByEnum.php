<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

// @TODO: move to baka/Enums if we need it in other places
enum DateGroupByEnum: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
}
