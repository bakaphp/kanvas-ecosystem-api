<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Connectors\Movipass\Notifications\Concerns\PushesConstructorContent;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideAssistanceStatusNotification extends CustomOrderNotification
{
    use PushesConstructorContent;

    public function __construct(
        Order $order,
        string $title,
        string $message,
        string $destinationEvent,
        array $via = ['push', 'database', 'expo'],
    ) {
        $assistanceCase = $order->metadata['assistance_case'] ?? ($order->metadata['data']['assistance_case'] ?? []);

        $data = [
            'email_template' => null,
            'push_template' => null,
            'app' => $order->app,
            'company' => $order->company,
            'title' => $title,
            'message' => $message,
            'metadata' => [
                'order_id' => $order->getId(),
                'order_uuid' => $order->uuid,
                'service' => $assistanceCase['service'] ?? null,
                'location' => $assistanceCase['location'] ?? [],
            ],
            'message_owner_id' => $order->users_id,
            'message_id' => $order->getId(),
            'parent_message_id' => $order->getId(),
            'destination_id' => $order->getId(),
            'destination_type' => 'ORDER',
            'destination_event' => $destinationEvent,
            'fromUser' => $order->user,
        ];

        parent::__construct($order, $data, $via);
    }
}
