<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Notifications;

use Kanvas\Insurance\Services\InsurancePolicyReminders;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;

class InsurancePolicyReminderNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
        public readonly int $daysLeft,
    ) {
        parent::__construct($order, [
            'app' => $order->app,
            'company' => $order->company,
        ]);

        $this->setType(InsurancePolicyReminders::TEMPLATE);
        $this->setTemplateName(InsurancePolicyReminders::TEMPLATE);
        $this->setPushTitleTemplateName(InsurancePolicyReminders::PUSH_TITLE_TEMPLATE);
        $this->setPushMessageTemplateName(InsurancePolicyReminders::PUSH_MESSAGE_TEMPLATE);
        $this->setDatabaseTemplateName(InsurancePolicyReminders::PUSH_MESSAGE_TEMPLATE);
        $this->setData($data);
        $this->channels = InsurancePolicyReminders::channels();
    }
}
