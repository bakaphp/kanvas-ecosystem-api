<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class PaymentReceiptNotification extends Notification
{
    public function __construct(
        Order $order,
        Payments $payment,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName(OrderTypes::DEFAULT_PAYMENT_RECEIPT_TEMPLATE);
        $this->setData($data);
    }
}
