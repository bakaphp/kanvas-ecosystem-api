<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Enums;

enum LeaveDecisionEnum: string
{
    case APPROVE = 'approve';
    case REJECT = 'reject';
}
