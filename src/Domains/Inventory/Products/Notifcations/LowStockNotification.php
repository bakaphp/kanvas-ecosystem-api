<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Notifications;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Enums\EmailTemplateEnum;
use Kanvas\Notifications\Notification;

class LowStockNotification extends Notification
{
    public function __construct(
        Companies $company,
        array $data,
    ) {
        parent::__construct($company, $data);
        //$this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::LOW_STOCK->value);
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }
}
