<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationChannelsRepository;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToOneFollowerAction
{
    public function __construct(
        protected Users $fromUser,
        protected Users $toUser,
        protected AppInterface $app,
        protected array $message
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function execute(): void
    {
        $follower = UsersFollowsRepository::getByUserAndEntity($this->toUser, $this->fromUser);

        if (in_array('push', $this->message['metadata']['channels'])) {
            $notificationChannel = NotificationChannelsRepository::getBySlug('push');
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                $notificationChannel->id,
                $this->message['metadata']['verb'],
                $this->message['metadata']['event'],
                $this->app
            );

            $buildPushTemplateNotification = new BuildPushTemplateNotificationAction(
                $notificationType->template()->firstOrFail()->template,
                $this->fromUser,
                $this->toUser,
                $this->message
            );
            $message = $buildPushTemplateNotification->execute();

            PushNotificationsHandlerJob::dispatch($follower->entity_id, $message);
        }

        if (in_array('mail', $this->message['metadata']['channels'])) {
            $notificationChannel = NotificationChannelsRepository::getBySlug('mail');
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                $notificationChannel->id,
                $this->message['metadata']['verb'],
                $this->message['metadata']['event'],
                $this->app
            );

            $data = [
                'fromUser' => $this->fromUser,
                'message' => $this->message,
                'app' => $this->app,
            ];

            // $notification->setFromUser(auth()->user());
            $this->toUser->notify(new Blank(
                $notificationType->template()->firstOrFail()->name,
                $data,
                ['mail'],
                $this->toUser
            ));
        }
    }
}
