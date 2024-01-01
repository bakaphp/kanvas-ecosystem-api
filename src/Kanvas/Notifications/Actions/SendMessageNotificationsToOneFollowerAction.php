<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToOneFollowerAction
{
    public function __construct(
        private Users $fromUser,
        private Users $toUser,
        private AppInterface $app,
        private NotificationTypes $notificationType,
        private MessagesNotificationMetadata $messagePayload
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function execute(): void
    {
        $follower = UsersFollowsRepository::getByUserAndEntity($this->toUser, $this->fromUser);

        if (! $follower) {
            return;
        }

        $dynamicNotification = new DynamicKanvasNotification(
            $this->notificationType,
            $this->messagePayload->message
        );

        $dynamicNotification->setFromUser($this->fromUser);
        $this->toUser->notify($dynamicNotification);
    }
}
