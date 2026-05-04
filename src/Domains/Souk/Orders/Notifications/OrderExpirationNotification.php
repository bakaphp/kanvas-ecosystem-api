<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum;
use Kanvas\Souk\Orders\Models\Order;

class OrderExpirationNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
        EmailTemplateEnum $template,
    ) {
        parent::__construct($order, $data);
        $this->setTemplateName($template->value);
        $this->setData($data);
        $this->setSubject($data['title'] ?? null);
    }

    public function getNotificationTitle(): ?string
    {
        return $this->data['title'] ?? parent::getNotificationTitle();
    }
}
