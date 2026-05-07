<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Souk\Orders\Models\Order;

class PendingOrderAssignmentNotification extends CustomOrderNotification
{
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
            'apps_id' => $this->app->getId(),
            'data' => $this->getData(),
        ];
    }

    public function __construct(Order $order, array $via = ['push', 'database'])
    {
        $assistanceCase = $order->metadata['assistance_case'] ?? ($order->metadata['data']['assistance_case'] ?? []);
        $service = $assistanceCase['service'] ?? 'servicio';
        $location = $assistanceCase['location'] ?? [];

        $data = [
            'email_template' => null,
            'push_template' => null,
            'app' => $order->app,
            'company' => $order->company,
            'title' => 'Nueva orden de asistencia disponible',
            'message' => "Hay una orden de {$service} esperando asignación.",
            'metadata' => [
                'order_id' => $order->getId(),
                'order_uuid' => $order->uuid,
                'service' => $service,
                'location' => $location,
            ],
            'message_owner_id' => $order->users_id,
            'message_id' => $order->getId(),
            'parent_message_id' => $order->getId(),
            'destination_id' => $order->getId(),
            'destination_type' => 'ORDER',
            'destination_event' => 'PENDING_ASSIGNMENT',
            'fromUser' => $order->user,
        ];

        parent::__construct($order, $data, $via);
    }
}
