<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Notifications\Channels\RespondIoSmsChannel;
use Kanvas\Souk\Orders\Models\Order;

class NewManualPaidOrderNotification extends Notification
{
    //use Queueable;

    public function __construct(
        protected Order $order
    ) {
    }

    /**
     * Get the notification channels.
     */
    public function via(object $notifiable)
    {
        return [RespondIoSmsChannel::class];
    }

    /**
     * Get the Respond.io SMS representation of the notification.
     */
    public function toRespondIoSms(object $notifiable)
    {
        return $this->order->company->get(CustomFieldEnum::SHOPIFY_MANUEL_ORDER_NOTIFICATION_MSG->value);
    }
}
