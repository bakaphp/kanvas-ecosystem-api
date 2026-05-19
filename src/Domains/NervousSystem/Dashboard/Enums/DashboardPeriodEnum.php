<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Enums;

enum DashboardPeriodEnum: string
{
    case TODAY = 'TODAY';
    case YESTERDAY = 'YESTERDAY';
    case THIS_WEEK = 'THIS_WEEK';
}
