<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class CustomOrderNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
        array $via
    ) {
        parent::__construct($order, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName($data['email_template']);
        $this->setPushTemplateName($data['push_template']);
        $this->setData($data);
        $this->setFromUser($data['fromUser'] ?? $order->user);
        $this->channels = $via;
    }
}
