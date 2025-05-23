<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class NewMessageNotification extends Notification
{
    public function __construct(
        Message $message,
        array $data,
        array $via
    ) {
        parent::__construct($message, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName(! empty($data['email_template']) ? $data['email_template'] : NotificationTemplateEnum::EMAIL_NEW_MESSAGE->value);
        $this->setPushTemplateName(! empty($data['push_template']) ? $data['push_template'] : NotificationTemplateEnum::PUSH_NEW_MESSAGE->value);
        $this->setData($data);
        $this->setFromUser($message->user);
        $this->channels = $via;
    }
}
