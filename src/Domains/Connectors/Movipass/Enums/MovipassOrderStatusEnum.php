<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassOrderStatusEnum: string
{
    case IN_TRANSIT = 'in_transit';
    case PENDING = 'pending';
    case AWAITING_DELIVERY_CONFIRMATION = 'awaiting_delivery_confirmation';
    case DELIVERED = 'delivered';
    case PAID = 'paid';
    case RELEASED = 'released_from_lot';
    case CANCELLED = 'cancelled';
    case TRIAL_PHASE = 'trial_phase';
}
