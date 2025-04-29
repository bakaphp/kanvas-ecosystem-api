<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class ImageProcessingPushNotification extends CustomMessageNotification
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
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setPushTemplateName($templates['push_template']);
        $this->setData($data);
        //$this->setFromUser($user);
        $this->channels = $via;
    }
}
