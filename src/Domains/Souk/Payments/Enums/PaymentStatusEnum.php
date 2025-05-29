<?php

namespace Kanvas\Souk\Payments\Enums;

enum PaymentStatusEnum: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case PENDING_AUTHORIZATION = 'pending_authorization';
    case CANCELLED = 'cancelled';
}
