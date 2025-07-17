<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Connectors\PromptMine\Enums\NotificationTypesEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Users\Models\Users;

class VideoProcessingPushNotification extends CustomMessageNotification
{
    public function __construct(
        Users $user,
        Message $entity,
        string $message,
        string $title,
        array $via,
        array $templates = []
    ) {
        $data = [
            'email_template' => $templates['email_template'] ?? null,
            'push_template' => $templates['push_template'] ?? null,
            'app' => $entity->app,
            'company' => $entity->company,
            'message' => $message,
            'title' => $title,
            'metadata' => $entity->getMessage(),
            'via' => $via,
            'message_owner_id' => $entity->user->getId(),
            'message_id' => $entity->getId(),
            'parent_message_id' => $entity->getId(),
            'destination_id' => $entity->getId(),
            'destination_type' => 'MESSAGE',
            'destination_event' => 'NEW_MESSAGE',
        ];

        parent::__construct($entity, $data, $via);
        $this->setType(NotificationTypesEnum::VIDEO_PROCESSING->value);
        $this->setPushTemplateName($templates['push_template']);
        $this->setData($data);
        $this->setInteraction(InteractionEnum::SYSTEM_INFO->getValue());
        $this->channels = $via;
    }
}
