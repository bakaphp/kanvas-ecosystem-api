<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Connectors\PromptMine\Enums\NotificationTypesEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Users\Models\Users;

class MessageOfTheWeekNotification extends CustomMessageNotification
{
    public function __construct(
        Users $user,
        Message $entity,
        string $message,
        string $title,
        array $via,
        array $templates = []
    ) {
        $metadata = $entity->getMessage();
        $data = [
            'email_template' => $templates['email_template'] ?? null,
            'push_template' => $templates['push_template'] ?? null,
            'app' => $entity->app,
            'company' => $entity->company,
            'message' => $message,
            'title' => $title,
            'metadata' => $metadata,
            'via' => $via,
            'message_owner_id' => $entity->user->getId(),
            'message_id' => $entity->getId(),
            'parent_message_id' => $entity->getId(),
            'destination_id' => $entity->getId(),
            'destination_type' => 'MESSAGE',
            'destination_event' => 'NEW_MESSAGE',
        ];
        parent::__construct($entity, $data, $via);
        $this->setType(NotificationTypesEnum::MESSAGE_OF_THE_WEEK->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value);
        $this->setData($data);
        $this->setFromUser($user);
        $this->channels = $via;
    }
}
