<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToOneFollowerAction
{
    /**
     * __construct.
     *
     * @param Users $user
     * @param array $message
     * @return void
     */
    public function __construct(
        private Users $user,
        private array $message
    ) {
    }

    /**
     * Send message notifications to a specific follower of a user.
     */
    public function execute(): void
    {
        $app = app(Apps::class);
        $LoggedUser = auth()->user();
        $follower = UsersFollowsRepository::getByUserAndEntity($this->user, $LoggedUser);

        if (in_array('push', $this->message['metadata']['channels'])) {
            PushNotificationsHandlerJob::dispatch($follower->users_id, $this->message);
        }

        if (in_array('mail', $this->message['metadata']['channels'])) {
            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent($this->message['metadata']['verb'], $this->message['metadata']['event'], $app);
            $user = Users::getById($follower->users_id);
            /** @todo Maybe here we could manipulate de message entity data? */
            $data = [
                'author' => $LoggedUser->displayname,
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
