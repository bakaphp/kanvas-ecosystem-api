<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class NewOrderStoreOwnerNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::NEW_ORDER_STORE_OWNER->value);
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }
}
