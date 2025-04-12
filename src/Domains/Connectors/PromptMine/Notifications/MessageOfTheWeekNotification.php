<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
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
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value);
        $this->setData($data);
        $this->setFromUser($user);
        $this->channels = $via;
    }
}
