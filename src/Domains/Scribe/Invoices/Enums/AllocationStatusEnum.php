<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

enum AllocationStatusEnum: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case REVERSED = 'reversed';
}
