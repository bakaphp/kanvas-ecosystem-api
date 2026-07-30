<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Enums;

enum LeaveRequestStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
