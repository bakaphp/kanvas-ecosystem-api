<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kanvas\Notifications\Actions\CreateNotificationAction;
use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;

class KanvasDatabase
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toKanvasDatabase($notifiable);
        $dto = NotificationsDto::from($message);
        $action = new CreateNotificationAction($dto);
        $action->execute();
    }
}
