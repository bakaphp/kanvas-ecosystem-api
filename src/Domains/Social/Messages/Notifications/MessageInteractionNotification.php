<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class MessageInteractionNotification extends Notification
{
    public function __construct(
        Message $message,
        array $data,
        array $via
    ) {
        parent::__construct($message, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName($data['email_template'] ?? NotificationTemplateEnum::EMAIL_NEW_INTERACTION_MESSAGE->value);
        $this->setPushTemplateName($data['push_template'] ?? NotificationTemplateEnum::PUSH_NEW_INTERACTION_MESSAGE->value);
        $this->setData($data);
        $this->setFromUser($data['fromUser'] ?? $message->user);
        $this->channels = $via;
    }
}
