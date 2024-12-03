<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;

class NewOrderNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType('blank');
        $this->setTemplateName('new-order');
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }
}
