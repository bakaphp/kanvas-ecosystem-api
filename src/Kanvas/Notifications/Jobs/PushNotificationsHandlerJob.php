<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Berkayk\OneSignal\OneSignalClient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Repositories\UsersLinkedSourcesRepository;

class PushNotificationsHandlerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

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
        $this->overwriteAppService($this->app);
        $userOneSignalId = UsersLinkedSourcesRepository::getByUsersId($this->usersFollowId)->source_users_id;

        if (getenv('APP_ENV') !== 'testing') {
            $oneSignalAppId = $this->app->get(AppSettingsEnums::ONE_SIGNAL_APP_ID->getValue());
            $oneSignalRestApiKey = $this->app->get(AppSettingsEnums::ONE_SIGNAL_REST_API_KEY->getValue());

            match (true) {
                empty($oneSignalAppId) => throw new Exception($this->app->name . ' OneSignal App ID is not set'),
                empty($oneSignalRestApiKey) => throw new Exception($this->app->name . ' OneSignal Rest API Key is not set'),
            };

            $oneSignalClient = new OneSignalClient($oneSignalAppId, $oneSignalRestApiKey, '');

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
