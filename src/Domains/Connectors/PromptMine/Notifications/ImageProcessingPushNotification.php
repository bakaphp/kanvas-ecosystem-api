<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class ImageProcessingPushNotification extends CustomMessageNotification
{
    public function __construct(
        Message $message,
        string $messageText,
        string $title,
        array $via,
        array $templates = []
    ) {
        $data = [
            'email_template' => $templates['email_template'] ?? null,
            'push_template' => $templates['push_template'] ?? null,
            'app' => $message->app,
            'company' => $message->company,
            'message' => $messageText,
            'title' => $title,
            'metadata' => $message->getMessage(),
            'via' => $via,
            'message_owner_id' => $message->user->getId(),
            'message_id' => $message->getId(),
            'parent_message_id' => $message->getId(),
            'destination_id' => $message->getId(),
            'destination_type' => 'MESSAGE',
            'destination_event' => 'NEW_MESSAGE',
        ];

        parent::__construct($message, $data, $via);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value);
        $this->setData($data);
        //$this->setFromUser($user);
        $this->channels = $via;
    }
}
