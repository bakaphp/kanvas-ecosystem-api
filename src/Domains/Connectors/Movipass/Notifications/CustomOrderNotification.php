<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;

class CustomOrderNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
        array $via
    ) {
        // No type here: a set type makes via() filter channels by the user's settings for it, and a
        // setting saved for the shared "blank" email type would strip push/expo from roadside pushes.
        parent::__construct($order, $data);
        if ($data['email_template'] !== null) {
            $this->setTemplateName($data['email_template']);
        }
        if ($data['push_template'] !== null) {
            $this->setPushTemplateName($data['push_template']);
        }
        $this->setData($data);
        $this->setFromUser($data['fromUser'] ?? $order->user);
        $this->channels = $via;
    }
}
