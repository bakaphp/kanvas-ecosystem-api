<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Baka\Contracts\AppInterface;

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
     * execute.
     */
    public function execute(): NotificationTypesMessageLogic
    {
        $notificationTypesMessageLogic = new NotificationTypesMessageLogic();
        $notificationTypesMessageLogic->apps_id = $this->app->getId();
        $notificationTypesMessageLogic->messages_type_id = $this->messageType->getId();
        $notificationTypesMessageLogic->notifications_type_id = $this->notificationType->getId();
        $notificationTypesMessageLogic->logic = $this->logic;
        $notificationTypesMessageLogic->created_at = date('Y-m-d H:i:s');
        $notificationTypesMessageLogic->is_deleted = 0;
        $notificationTypesMessageLogic->saveOrFail();

        return $notificationTypesMessageLogic;
    }
}
