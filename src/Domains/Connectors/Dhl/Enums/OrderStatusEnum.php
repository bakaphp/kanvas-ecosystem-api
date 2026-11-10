<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

enum OrderStatusEnum: string
{
    case VERIFYING = 'verifying';
    case PAID = 'paid';
    case READY_FOR_DISPATCH = 'ready-for-dispatch';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case OUT_OF_STOCK = 'out-of-stock';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';
}
