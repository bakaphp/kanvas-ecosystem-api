<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Notifications;

use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Follows\Enums\NotificationTemplateEnum;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class NewFollowerNotification extends Notification
{
    public function __construct(
        Users $user,
        array $data,
    ) {
        parent::__construct($user, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName(NotificationTemplateEnum::EMAIL_NEW_FOLLOWER->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_NEW_FOLLOWER->value);
        $this->setData($data);
        $this->channels = [
            NotificationChannelEnum::getNotificationChannelBySlug('mail'),
            NotificationChannelEnum::getNotificationChannelBySlug('push'),
            NotificationChannelEnum::getNotificationChannelBySlug('database'),
        ];
    }
}
