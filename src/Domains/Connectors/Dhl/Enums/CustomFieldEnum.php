<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

enum CustomFieldEnum: string
{
    case BOOKING_STATE = 'dhl_booking_state';
    case DISPATCH_CONFIRMATION_NUMBER = 'dhl_dispatch_confirmation_number';
    case TRACKING = 'dhl_tracking';
    case DELIVERED_AT = 'dhl_delivered_at';
    case TRACKING_UPDATED_AT = 'dhl_tracking_updated_at';
    case EPOD_STORED_AT = 'dhl_epod_stored_at';
    case UNVERIFIED_ALERTED_AT = 'dhl_unverified_alerted_at';
}
