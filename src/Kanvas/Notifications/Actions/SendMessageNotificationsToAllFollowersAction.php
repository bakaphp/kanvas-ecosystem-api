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

class SendMessageNotificationsToAllFollowersAction
{
    public function __construct(
        protected Users $user,
        protected AppInterface $app,
        protected array $message
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function execute(): void
    {
        $fromUser = $this->user;
        $followers = UsersFollowsRepository::getFollowersBuilder($fromUser)->get();

        foreach ($followers as $follower) {

            $toUser = Users::getById($follower->getOriginal()['id']);

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
                    $fromUser,
                    $toUser,
                    $this->message
                );
                $message = $buildPushTemplateNotification->execute();

                PushNotificationsHandlerJob::dispatch($follower->getOriginal()['id'], $message, $notificationType);
            }

            if (in_array('mail', $this->message['metadata']['channels'])) {

                $notificationChannel = NotificationChannelRepository::getBySlug($this->message['metadata']['channels']);
                $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                    $notificationChannel->id,
                    $this->message['metadata']['verb'],
                    $this->message['metadata']['event'],
                    $this->app
                );

                $data = [
                    'fromUser' => $fromUser,
                    'message' => $this->message,
                    'app' => $this->app,
                ];

                // $notification->setFromUser(auth()->user());
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
