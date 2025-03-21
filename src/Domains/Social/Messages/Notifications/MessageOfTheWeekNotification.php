<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class MessageOfTheWeekNotification extends Notification
{
    public function __construct(
        Users $user,
        array $data,
        array $via
    ) {
        parent::__construct($user, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setPushTemplateName($data['push_template'] ?? NotificationTemplateEnum::PUSH_NEW_MESSAGE->value);
        $this->setData($data);
        $this->setFromUser($user);
        $this->channels = $via;
    }
}
