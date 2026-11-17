<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

enum DhlTrackingEventEnum: string
{
    case DELIVERED = 'OK';
    case RETURNED_TO_SHIPPER = 'RT';
    case DELIVERY_REFUSED = 'RD';
    case NOT_HOME = 'NH';

    public function orderStatus(): ?OrderStatusEnum
    {
        return match ($this) {
            self::DELIVERED => OrderStatusEnum::DELIVERED,
            self::RETURNED_TO_SHIPPER, self::DELIVERY_REFUSED => OrderStatusEnum::RETURNED,
            self::NOT_HOME => null,
        };
    }
}
