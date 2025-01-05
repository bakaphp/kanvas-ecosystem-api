<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Notifications;

use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class NewMessageNotification extends Notification
{
    public function __construct(
        Message $message,
        array $data,
        array $via
    ) {
        parent::__construct($message, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName(NotificationTemplateEnum::EMAIL_NEW_MESSAGE->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_NEW_MESSAGE->value);
        $this->setData($data);
        $this->channels = $via;
    }
}
