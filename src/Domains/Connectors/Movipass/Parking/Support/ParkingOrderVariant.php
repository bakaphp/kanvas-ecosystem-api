<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Support;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

final class ParkingOrderVariant
{
    public static function from(Order $order): Variants
    {
        $variant = $order->items->first()?->variant;

        if (! $variant instanceof Variants || $variant->product === null) {
            throw new ValidationException('The order has no parking product.');
        }

        return $variant;
    }
}
