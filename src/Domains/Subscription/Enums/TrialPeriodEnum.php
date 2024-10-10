<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Enums;

enum TrialPeriodEnum: int
{
    case MONTHLY = 30;
    case BIWEEKLY = 15;
}