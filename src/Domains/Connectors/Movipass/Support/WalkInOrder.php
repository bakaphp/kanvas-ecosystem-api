<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Souk\Orders\Models\Order;

final class WalkInOrder
{
    public static function is(Order $order): bool
    {
        return self::accessOf($order) !== null;
    }

    public static function accessOf(Order $order): ?WalkInAccess
    {
        if (! $order->isEventBacked()) {
            return null;
        }

        $event = Event::find($order->resources_id);

        return WalkInAccess::forResource($event?->resource);
    }
}
