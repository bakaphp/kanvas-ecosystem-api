<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliatePayoutStatusEnum: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
