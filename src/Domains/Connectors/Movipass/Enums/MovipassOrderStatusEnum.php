<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassOrderStatusEnum: string
{
    case REQUEST_SUBMITTED = 'request_submitted';
    case PROVIDER_ASSIGNED = 'provider_assigned';
    case DISPATCHED = 'dispatched';
    case ON_SITE = 'on_site';
    case SERVICE_IN_PROGRESS = 'service_in_progress';
    case SERVICE_COMPLETED = 'service_completed';
    case SERVICE_CANCELLED = 'service_cancelled';
    case IN_TRANSIT = 'in_transit';
    case PENDING = 'pending';
    case AWAITING_DELIVERY_CONFIRMATION = 'awaiting_delivery_confirmation';
    case DELIVERED = 'delivered';
    case PAID = 'paid';
    case RELEASED = 'released_from_lot';
    case CANCELLED = 'cancelled';
    case TRIAL_PHASE = 'trial_phase';
}
