<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;

class OrderEmailMutation
{
    public function sendOrderEmail(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $order = Order::getById($request['order_id'], $app);

        $order->user->notify(new NewOrderNotification($order, [
            'app' => $app,
            'company' => $order->company,
        ]));

        return true;
    }
}
