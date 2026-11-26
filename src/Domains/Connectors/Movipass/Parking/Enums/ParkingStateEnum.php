<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Enums;

enum ParkingStateEnum: string
{
    case IN_PROGRESS = 'in_progress';
    case PAYMENT_APPROVED = 'payment_approved';
    case PENDING_CONFIRMATION = 'pending_confirmation';
    case EXIT_ENABLED = 'exit_enabled';
    case FINISHED = 'finished';
    case CANCELLED = 'cancelled';
}
