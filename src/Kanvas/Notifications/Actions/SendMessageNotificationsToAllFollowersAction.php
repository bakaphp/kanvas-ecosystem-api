<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class SendMessageNotificationsToAllFollowersAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private array $message
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function execute(): void
    {
        $app = app(Apps::class);
        $LoggedUser = auth()->user();
        $followers = UsersFollowsRepository::getFollowersBuilder($LoggedUser)->get();

        foreach ($followers as $follower) {
            if (in_array('push', $this->message['metadata']['channels'])) {
                PushNotificationsHandlerJob::dispatch($follower->getOriginal()['id'], $this->message);
            }

            if (in_array('mail', $this->message['metadata']['channels'])) {
                $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent($this->message['metadata']['verb'], $this->message['metadata']['event'], $app);
                $user = Users::getById($follower->getOriginal()['id']);

                $data = [
                    'fromUser' => $LoggedUser,
                    'message' => $this->message,
                    'app' => $app
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
}
