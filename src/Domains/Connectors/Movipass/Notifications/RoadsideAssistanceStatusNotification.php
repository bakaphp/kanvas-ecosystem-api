<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class RoadsideAssistanceStatusNotification extends CustomOrderNotification
{
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

    // The title/message come per-event from the constructor, not from a stored push
    // template (push_template is null). Emit them as the JSON shape getPushContent()
    // expects so the Expo channel ships without trying to render a missing DB template.
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
