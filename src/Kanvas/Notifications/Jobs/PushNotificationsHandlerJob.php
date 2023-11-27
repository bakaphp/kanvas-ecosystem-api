<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Berkayk\OneSignal\OneSignalFacade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    public function __construct(
        private int $usersFollowId,
        private array $message,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        $userOneSignalId = UsersLinkedSourcesRepository::getByUsersId($this->usersFollowId)->source_users_id;

        if (getenv('APP_ENV') !== 'testing') {
            OneSignalFacade::sendNotificationToUser(
                $this->message['message'],
                $userOneSignalId,
                $url = null,
                $data = null,
                $buttons = null,
                $schedule = null,
                $this->message['subtitle'],
                $this->message['title']
            );
        }
    }
}
