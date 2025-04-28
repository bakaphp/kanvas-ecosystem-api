<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class CustomMessageNotification extends Notification
{
    public function __construct(
        Message $message,
        array $data,
        array $via
    ) {
        parent::__construct($message, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName($data['email_template']);
        $this->setPushTemplateName($data['push_template']);
        $this->setData($data);
        $this->setFromUser($data['fromUser'] ?? $message->user);
        $this->channels = $via;
    }
}
