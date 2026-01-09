<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Notifications;

use Kanvas\Connectors\PromptMine\Enums\NotificationTypesEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Users\Models\Users;

class FollowsRecommendationsPushNotication extends Notification
{
    public function __construct(
        Users $entity,
        string $title,
        string $message,
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
            'metadata' => $entity->toArray(),
            'via' => $via,
            'destination_id' => $entity->getId(),
            'destination_type' => 'USER',
            'destination_event' => 'FOLLOWING',
        ];

        parent::__construct($entity, $data, $via);
        $this->setType(NotificationTypesEnum::FOLLOW_RECOMMENDATION->value);
        $this->setPushTemplateName($templates['push_template']);
        $this->setData($data);
        $this->setInteraction(InteractionEnum::RECOMMENDATIONS->getValue());
        $this->channels = $via;
    }
}
