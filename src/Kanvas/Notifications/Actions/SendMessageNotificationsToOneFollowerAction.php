<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToOneFollowerAction
{
    public function __construct(
        protected Users $user,
        protected Users $follower,
        protected AppInterface $app,
        protected array $message
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function execute(): void
    {
        $LoggedUser = $this->user;
        $follower = UsersFollowsRepository::getByUserAndEntity($this->follower, $LoggedUser);

        if (in_array('push', $this->message['metadata']['channels'])) {
            PushNotificationsHandlerJob::dispatch($follower->users_id, $this->message);
        }

        if (in_array('mail', $this->message['metadata']['channels'])) {
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(
                $this->message['metadata']['verb'],
                $this->message['metadata']['event'],
                $this->app
            );
            $user = Users::getById($follower->users_id);
            /** @todo Maybe here we could manipulate de message entity data? */
            $data = [
                'fromUser' => $LoggedUser,
                'message' => $this->message,
                'app' => $this->app,
            ];

            // $notification->setFromUser(auth()->user());
            $user->notify(new Blank(
                $notificationType->template()->firstOrFail()->name,
                $data,
                ['mail'],
                $user
            ));
        }
    }
}
