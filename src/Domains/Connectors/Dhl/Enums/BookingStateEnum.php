<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

enum BookingStateEnum: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case MANUAL_REVIEW = 'manual_review';
}
