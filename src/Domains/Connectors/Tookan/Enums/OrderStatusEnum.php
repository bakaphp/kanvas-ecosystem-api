<?php

namespace Kanvas\Connectors\Tookan\Enums;

enum OrderStatusEnum: string
{
    case RECEIVED = 'received';
    case CHECKING_STOCK = 'checking_stock';
    case PREPARING = 'preparing';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case PREPARING_PACKAGING = 'preparing_packaging';
    case PACKAGING_READY = 'packaging_ready';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case OUT_OF_STOCK = 'out_of_stock';
    case CANCELLED = 'cancelled';
}
