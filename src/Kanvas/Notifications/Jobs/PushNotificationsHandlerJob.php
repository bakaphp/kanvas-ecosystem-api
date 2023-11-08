<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Berkayk\OneSignal\OneSignalFacade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Repositories\UsersLinkedSourcesRepository;

class PushNotificationsHandlerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(private UsersFollows $usersFollow, private array $message)
    {
        $this->onQueue('notifications');
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        //get users linked sources here of the follower

        $userOneSignalId = UsersLinkedSourcesRepository::getByUsersId($this->usersFollow->getOriginal()['id'])->source_users_id;
        OneSignalFacade::sendNotificationToUser(
            $this->message['metadata']['notification_content']['message'],
            $userOneSignalId,
            $url = null,
            $data = null,
            $buttons = null,
            $schedule = null,
            $this->message['metadata']['notification_content']['subtitle'],
            $this->message['metadata']['notification_content']['title']
        );
    }
}
