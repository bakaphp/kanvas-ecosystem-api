<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;

class OrderEmailMutation
{
    public function sendOrderEmail(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $order = Order::getById($request['order_id'], $app);
        $template = $request['template'] ?? null;

        if ($template) {
            new SendOrderEmailsAction($order, $template)->execute();

            return true;
        }

        $order->user->notify(new NewOrderNotification($order, [
            'app' => $app,
            'company' => $order->company,
            'force_send' => true,
            'order' => $order,
        ]));

        return true;
    }
}
