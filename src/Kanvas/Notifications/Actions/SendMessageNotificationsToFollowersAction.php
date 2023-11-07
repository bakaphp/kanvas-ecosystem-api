<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Apps\Models\Apps;

class SendMessageNotificationsToFollowersAction
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
            foreach ($this->message['metadata']['channels'] as $channel) {
                switch ($channel) {
                    case 'push':
                        PushNotificationsHandlerJob::dispatch($follower, $this->message);

                        break;
                    case 'mail':

                        $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent($this->message['metadata']['verb'], $this->message['metadata']['event'], $app);
                        $user = Users::getById($follower->getOriginal()['id']);

                        $data = [
                            'author' => $LoggedUser->displayname
                        ];

                        // $notification->setFromUser(auth()->user());
                        $user->notify(new Blank(
                            $notificationType->template()->firstOrFail()->name,
                            $data,
                            ['mail'],
                            $user
                        ));

                        break;
                    default:
                        # code...
                        break;
                }
            }
        }
    }
}
