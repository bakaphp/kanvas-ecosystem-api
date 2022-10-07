<?php

declare(strict_types=1);
namespace Kanvas\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;
use Kanvas\Notifications\Actions\CreateNotification as CreateNotificationAction;

class KanvasDatabase
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toKanvasDatabase($notifiable);
        $dto = NotificationsDto::fromArray($message);
        $action = new CreateNotificationAction($dto);
        $action->execute();
    }
}
