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
use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;
use Kanvas\Notifications\Actions\CreateNotificationAction;

class SendMessageNotificationsToAllFollowersAction
{
    public function __construct(
        protected Users $fromUser,
        protected AppInterface $app,
        protected array $message
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
                    $toUser,
                    $this->message
                );
                $message = $buildPushTemplateNotification->execute();

                PushNotificationsHandlerJob::dispatch($follower->getId(), $message);

                $notificationArray = [
                    'users_id' => $toUser->getId(),
                    'from_users_id' => $this->fromUser->getId(),
                    'companies_id' => $this->fromUser->defaultCompany(),
                    'apps_id' => $this->app->getId(),
                    'system_modules_id' => $notificationType->system_modules_id,
                    'notification_type_id' => $notificationType->getId(),
                    'entity_id' => $toUser->getId(),
                    'content' => implode(',', $message),
                    'read' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'is_deleted' => 0,
                ];

                $dto = NotificationsDto::fromArray($notificationArray);
                $createNotification = new CreateNotificationAction($dto);
                $createNotification->execute();
            }

            if (in_array('mail', $this->message['metadata']['channels'])) {
                $notificationChannel = NotificationChannelsRepository::getBySlug('email');
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
