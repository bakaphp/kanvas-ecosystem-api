<?php

namespace Kanvas\Connectors\Movipass\Enums;

class MovipassOrderStatusEnum
{
    public const IN_TRANSIT = 'in_transit';
    public const PENDING = 'pending';
    public const AWAITING_DELIVERY_CONFIRMATION = 'awaiting_delivery_confirmation';
    public const DELIVERED = 'delivered';
    public const PAID = 'paid';
    public const RELEASED = 'released';
    public const CANCELLED = 'cancelled';
}