<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Connectors\Movipass\Enums\EmailTemplateEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;

class OrdersExportNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType(EmailTemplateEnum::TYPE_BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::ORDERS_EXPORT->value);
        $this->setData($data);
    }
}
