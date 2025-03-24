<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class MonthlyMessageCreationNotification extends Notification
{
    public function __construct(
        Users $user,
        array $data,
        array $via
    ) {
        parent::__construct($user, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_MONTHLY_PROMPT_COUNT->value);
        $this->setData($data);
        $this->setFromUser($user);
        $this->channels = $via;
    }
}
