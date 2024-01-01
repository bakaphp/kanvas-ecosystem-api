<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Events\PushNotificationsEvent;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationChannelsRepository;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToAllFollowersAction
{
    public function __construct(
        protected Users $fromUser,
        protected AppInterface $app,
        private NotificationTypes $notificationType,
        private MessagesNotificationMetadata $messagePayload,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function execute(): void
    {
        $followers = UsersFollowsRepository::getFollowersBuilder($this->fromUser, $this->app)->get();

        foreach ($followers as $follower) {
            $toUser = Users::getById($follower->getOriginal()['id']);

            $dynamicNotification = new DynamicKanvasNotification(
                $this->notificationType,
                $this->messagePayload->message
            );
    
            $dynamicNotification->setFromUser($this->fromUser);
            $toUser->notify($dynamicNotification);
        }
    }
}
