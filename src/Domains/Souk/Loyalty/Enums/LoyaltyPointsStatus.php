<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum LoyaltyPointsStatus: string
{
    case PENDING = 'pending';
    case CREDITED = 'credited';
    case REVERSED = 'reversed';
}
