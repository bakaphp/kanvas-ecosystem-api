<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Enums;

enum ReferralStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}