<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Observers;

use Kanvas\Souk\Orders\Models\Order;

class OrderObserver
{
    public function creating(Order $order): void
    {
        if (empty($order->order_number)) {
            $order->order_number = $order->generateOrderNumber();
        }
    }
}
