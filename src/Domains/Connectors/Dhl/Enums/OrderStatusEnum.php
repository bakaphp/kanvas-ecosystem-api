<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

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

    public function forApp(AppInterface $app): ?OrderStatus
    {
        $type = OrderTypes::query()
            ->where('apps_id', $app->getId())
            ->where('name', OrderTypeEnum::DELIVERY->value)
            ->first();

        return $type === null
            ? null
            : OrderStatus::query()
                ->where('order_types_id', $type->getId())
                ->where('slug', $this->value)
                ->first();
    }
}
