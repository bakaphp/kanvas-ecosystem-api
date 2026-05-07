<?php

namespace Kanvas\Souk\Payments\Enums;

enum PaymentStatusEnum: string
{
    case PENDING = 'pending';
    // transitional statuses
    case WAITING_DEVICE_DATA = 'waiting_device_data';
    case PENDING_AUTHORIZATION = 'pending_authorization';
    case PROCESSING = 'processing';
    // authorized
    case AUTHORIZED = 'authorized';
    // final states
    case PAID = 'paid';
    case FAILED = 'failed';
    case REVERSED = 'refunded';
    case CANCELLED = 'cancelled';
}
