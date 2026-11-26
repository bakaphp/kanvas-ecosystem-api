<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Enums;

/**
 * Lifecycle of a sales order. Commercial confirms it (gate 1), Operations accepts it with firm dates or returns
 * it (gate 2); the shipping states follow once the order is on its way.
 */
enum SalesOrderStatusEnum: string
{
    case RECEIVED = 'received';
    case CONFIRMED = 'confirmed';
    case ACCEPTED = 'accepted';
    case RETURNED = 'returned';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
}
