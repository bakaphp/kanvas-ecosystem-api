<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications;

use Kanvas\Connectors\Movipass\Enums\EmailTemplateEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;

class PaymentReceiptNotification extends Notification
{
    public function __construct(
        Order $order,
        Payments $payment,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType(EmailTemplateEnum::TYPE_BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::PAYMENT_RECEIPT->value);
        $this->setData($data);
    }
}
