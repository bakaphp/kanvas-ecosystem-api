<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Enums;

enum AccrualMethodEnum: string
{
    case ANNUAL_ALLOTMENT = 'annual_allotment';
    case MONTHLY_ACCRUAL = 'monthly_accrual';
    case UNLIMITED = 'unlimited';
}
