<?php

namespace Kanvas\Notifications\Listeners;

use Kanvas\Notifications\Actions\CreateNotificationAction;
use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;
use Kanvas\Notifications\Events\PushNotificationsEvent;

class NotificationsListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     */
    public function handle(PushNotificationsEvent $event): void
    {
        $notificationArray = [
            'users_id' => $event->toUser->getId(),
            'from_users_id' => $event->fromUser->getId(),
            'companies_id' => $event->fromUser->defaultCompany(),
            'apps_id' => $event->app->getId(),
            'system_modules_id' => $event->notificationType->system_modules_id,
            'notification_type_id' => $event->notificationType->getId(),
            'entity_id' => $event->toUser->getId(),
            'content' => implode(',', $event->message),
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ];

        $dto = NotificationsDto::fromArray($notificationArray);
        $createNotification = new CreateNotificationAction($dto);
        $createNotification->execute();
    }
}
