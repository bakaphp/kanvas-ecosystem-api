<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Observers;

use Kanvas\Souk\Discounts\Actions\RestoreCreditFromCancelledOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Events\OrderUpdateEvent;
use Kanvas\Souk\Orders\Models\Order;

class OrderObserver
{
    public function creating(Order $order): void
    {
        if (empty($order->order_number)) {
            $order->order_number = $order->generateOrderNumber();
        }
    }

    public function updated(Order $order): void
    {
        OrderUpdateEvent::dispatch($order);

        if ($order->wasChanged('status') && $order->status === OrderStatusEnum::CANCELED->value) {
            new RestoreCreditFromCancelledOrderAction($order)->execute();
        }
    }
}
