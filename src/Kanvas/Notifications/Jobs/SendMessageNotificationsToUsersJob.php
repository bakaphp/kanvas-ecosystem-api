<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SendMessageNotificationsToUsersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Users $fromUser,
        protected AppInterface $app,
        protected NotificationTypes $notificationType,
        protected MessagesNotificationMetadata $messagePayload
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        $dynamicNotification = new DynamicKanvasNotification(
            $this->notificationType,
            $this->messagePayload->message
        );

        $dynamicNotification->setFromUser($this->fromUser);

        foreach ($this->messagePayload->usersId as $userId) {
            $toUser = Users::getById($userId);
            UsersRepository::belongsToThisApp($toUser, $this->app);

            $toUser->notify($dynamicNotification);
        }
    }
}
