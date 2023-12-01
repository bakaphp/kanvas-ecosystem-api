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
use Kanvas\Notifications\Events\PushNotificationsEvent;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationsPayloadDto;

class SendMessageNotificationsToOneFollowerAction
{
    public function __construct(
        private Users $fromUser,
        private Users $toUser,
        private AppInterface $app,
        private MessagesNotificationsPayloadDto $messagePayload,
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function execute(): void
    {
        $follower = UsersFollowsRepository::getByUserAndEntity($this->toUser, $this->fromUser);

        if (in_array('push', $this->messagePayload->channels)) {
            $notificationChannel = NotificationChannelsRepository::getBySlug('push');
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                $notificationChannel->id,
                $this->messagePayload->verb,
                $this->messagePayload->event,
                $this->app
            );

            $buildPushTemplateNotification = new BuildPushTemplateNotificationAction(
                $notificationType->template()->firstOrFail()->template,
                $this->fromUser,
                $this->toUser,
                $this->messagePayload->message
            );
            $message = $buildPushTemplateNotification->execute();

            PushNotificationsHandlerJob::dispatch($follower->entity_id, $message, $this->app);

            PushNotificationsEvent::dispatch(
                $this->fromUser,
                $this->toUser,
                $notificationType,
                $this->app,
                $message
            );
        }

        if (in_array('email', $this->messagePayload->channels)) {
            $notificationChannel = NotificationChannelsRepository::getBySlug('email');
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                $notificationChannel->id,
                $this->messagePayload->verb,
                $this->messagePayload->event,
                $this->app
            );

            $data = [
                'fromUser' => $this->fromUser,
                'message' => $this->messagePayload->message,
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
