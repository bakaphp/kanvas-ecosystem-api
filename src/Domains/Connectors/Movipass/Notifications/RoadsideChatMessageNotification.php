<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class RoadsideChatMessageNotification extends CustomOrderNotification
{
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

    public function toOneSignal(UserInterface|AnonymousNotifiable $notifiable): array
    {
        if (! ($notifiable instanceof UserInterface)) {
            return [];
        }

        return [
            'user_id' => $notifiable->getId(),
            'message' => $this->data['message'] ?? '',
            'title' => $this->data['title'] ?? '',
            'subtitle' => '',
            'apps_id' => $this->data['app']->getId(),
            'data' => $this->getData(),
        ];
    }

    // The title/message come from the constructor, not from a stored push template
    // (push_template is null). Emit them as the JSON shape getPushContent() expects so
    // the Expo channel ships without trying to render a missing DB template.
    #[Override]
    protected function getPushTemplate(): string
    {
        return json_encode([
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'subtitle' => null,
        ], JSON_THROW_ON_ERROR);
    }
}
