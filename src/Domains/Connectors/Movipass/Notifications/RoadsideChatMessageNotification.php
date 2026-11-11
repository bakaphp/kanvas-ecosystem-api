<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Connectors\Movipass\Notifications\Concerns\PushesConstructorContent;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideChatMessageNotification extends CustomOrderNotification
{
    use PushesConstructorContent;

    public function __construct(
        Order $order,
        string $channelSlug,
        string $senderName,
        string $preview,
        int $chatMessageId,
        array $via = ['push', 'database', 'expo'],
    ) {
        $data = [
            'email_template' => null,
            'push_template' => null,
            'app' => $order->app,
            'company' => $order->company,
            'title' => "New message from {$senderName}",
            'message' => $preview,
            // Top-level scalar so it survives the Expo scalar-only data filter; the app
            // uses it to deep-link into the chat via channelMessages(channel_slug: ...).
            'channel_slug' => $channelSlug,
            'metadata' => [
                'order_id' => $order->getId(),
                'order_uuid' => $order->uuid,
                'channel_slug' => $channelSlug,
                'chat_message_id' => $chatMessageId,
            ],
            'message_owner_id' => $order->users_id,
            'message_id' => $chatMessageId,
            'parent_message_id' => $chatMessageId,
            'destination_id' => $channelSlug,
            'destination_type' => 'CHANNEL',
            'destination_event' => 'NEW_MESSAGE',
            'fromUser' => $order->user,
        ];

        parent::__construct($order, $data, $via);
    }
}
