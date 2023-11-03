<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Follows\Models\UsersFollows;
use OneSignal;

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
        $userId = 'ebef012f-6a32-4447-bb6b-ccd23697ede7';
        OneSignal::sendNotificationToUser(
            $this->message['metadata']['notification_content']['message'],
            $userId,
            $url = null,
            $data = null,
            $buttons = null,
            $schedule = null,
            $this->message['metadata']['notification_content']['subtitle'],
            $this->message['metadata']['notification_content']['title']
        );

    }
}
