<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;

/**
 * "You have a message" — the receptionist took a message for a staff member and pings them.
 * In-app + push only (no email template) so it works without any template wiring. The entity is
 * any Guild record the message was taken on (a Lead or a Deal); its owner is the notifiable.
 */
class ReceptionistMessageNotification extends Notification
{
    public function __construct(
        BaseModel $entity,
        array $data,
    ) {
        parent::__construct($entity, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setData($data);
        $this->channels = ['push', 'database'];
    }
}
