<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum OfferAssignmentStatus: string
{
    case ASSIGNED = 'assigned';
    case VIEWED = 'viewed';
    case ACCEPTED = 'accepted';
    case EXPIRED = 'expired';
}