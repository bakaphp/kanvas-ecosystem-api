<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;

enum DhlTrackingEventEnum: string
{
    case DELIVERED = 'OK';
    case RETURNED_TO_SHIPPER = 'RT';
    case DELIVERY_REFUSED = 'RD';
    case NOT_HOME = 'NH';

    public function orderStatus(): ?DeliveryOrderStatusEnum
    {
        return match ($this) {
            self::DELIVERED => DeliveryOrderStatusEnum::DELIVERED,
            self::RETURNED_TO_SHIPPER, self::DELIVERY_REFUSED => DeliveryOrderStatusEnum::RETURNED,
            self::NOT_HOME => null,
        };
    }
}
