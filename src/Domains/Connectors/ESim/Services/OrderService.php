<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Kanvas\Connectors\ESim\Client;
use Kanvas\Souk\Orders\Models\Order;

class OrderService
{
    protected Client $client;

    public function __construct(
        protected Order $order
    ) {
        $this->client = new Client($order->app, $order->company);
    }

    public function createOrder()
    {
        $item = $this->order->items()->first();

        return $this->client->post('orders', [
            'type' => 'bundle', //@todo replace
            'quantity' => $item->quantity,
            'item' => $item->product_sku,
        ]);
    }
}
