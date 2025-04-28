<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class ImageProcessingPushNotification extends CustomMessageNotification
{
    public function __construct(
        Users $user,
        Model $entity,
        string $message,
        string $title,
        array $via,
        array $templates = []
    ) {
        $data = [
            'email_template' => $templates['email_template'] ?? null,
            'push_template' => $templates['push_template'] ?? null,
            'app' => $user->app,
            'company' => $user->company,
            'message' => $message,
            'title' => $title,
            'metadata' => $entity->getMessage(),
            'via' => $via,
            'message_owner_id' => $user->user->getId(),
            'message_id' => $user->getId(),
            'parent_message_id' => $user->getId(),
            'destination_id' => $user->getId(),
            'destination_type' => 'MESSAGE',
            'destination_event' => 'NEW_MESSAGE',
        ];

        parent::__construct($user, $data, $via);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value);
        $this->setData($data);
        $this->setFromUser($user);
        $this->channels = $via;
    }
}
