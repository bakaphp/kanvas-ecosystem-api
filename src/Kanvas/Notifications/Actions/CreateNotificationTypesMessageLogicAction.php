<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class CreateNotificationTypesMessageLogicAction
{
    /**
     * __construct.
     */
    public function __construct(
        private AppInterface $app,
        private MessageType $messageType,
        private NotificationTypes $notificationType,
        private string $logic
    ) {
    }

    /**
     * @psalm-suppress MixedInferredReturnType
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): NotificationTypesMessageLogic
    {
        return NotificationTypesMessageLogic::create([
            'apps_id' => $this->app->getId(),
            'messages_type_id' => $this->messageType->getId(),
            'notifications_type_id' => $this->notificationType->getId(),
            'logic' => $this->logic,
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
    }
}
