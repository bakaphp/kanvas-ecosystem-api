<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Baka\Contracts\AppInterface;
use Berkayk\OneSignal\OneSignalClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Repositories\SettingsRepository;
use Kanvas\Users\Repositories\UsersLinkedSourcesRepository;
use Kanvas\Enums\AppSettingsEnums;

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
        private AppInterface $app
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
            $oneSignalAppId = SettingsRepository::getByName(AppSettingsEnums::ONE_SIGNAL_APP_ID->getValue(), $this->app);
            $oneSignalRestApiKey = SettingsRepository::getByName(AppSettingsEnums::ONE_SIGNAL_REST_API_KEY->getValue(), $this->app);
            $oneSignalClient = new OneSignalClient($oneSignalAppId->value, $oneSignalRestApiKey->value, '');

            $oneSignalClient->sendNotificationToUser(
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
