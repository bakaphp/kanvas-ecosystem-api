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

class SendMessageNotificationsToAllFollowersAction
{
    public function __construct(
        protected Users $fromUser,
        protected AppInterface $app,
        private MessagesNotificationsPayloadDto $messagePayload,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function execute(): void
    {
        $followers = UsersFollowsRepository::getFollowersBuilder($this->fromUser)->get();

        foreach ($followers as $follower) {
            $toUser = Users::getById($follower->getOriginal()['id']);

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
                    $toUser,
                    $this->messagePayload->message
                );
                $message = $buildPushTemplateNotification->execute();

                PushNotificationsHandlerJob::dispatch($follower->getId(), $message, $this->app);

                PushNotificationsEvent::dispatch(
                    $this->fromUser,
                    $toUser,
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

                $toUser->notify(new Blank(
                    $notificationType->template()->firstOrFail()->name,
                    $data,
                    ['mail'],
                    $toUser
                ));
            }
        }
    }
}
