<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Kanvas\Connectors\ESim\Client;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
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

        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');
        $totalDays = $item->variant->getAttributeByName('esim_days');
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        return $this->client->post('/v2/esimgo/create/order', [
            'bundles' => [
                [
                    'type' => 'bundle',
                    'quantity' => $item->quantity,
                    'item' => $item->product_sku,
                ],
            ],
            //'quantity' => $item->quantity,
            //'item' => $item->product_sku,
            'total' => $this->order->total_net_amount,
            'total_days' => $totalDays->value,
            'wc_order_id' => 0,
            'device_id' => $channelId,
            'client' => [
                'first_name' => $this->order?->people?->first_name,
                'last_name' => $this->order?->people?->last_name,
                'phone' => $this->order->user_phone,
                'email' => $this->order->user_email,
                'payment' => null,
                'imei_number' => null,
            ],
        ]);
    }
}
