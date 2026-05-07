<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case INACTIVE = 'inactive';
    case REJECTED = 'rejected';
}
