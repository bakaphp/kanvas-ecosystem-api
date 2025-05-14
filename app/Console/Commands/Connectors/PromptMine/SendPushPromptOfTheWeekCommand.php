<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Jobs\SendMessageOfTheWeekJob;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\UsersAssociatedApps;

class SendPushPromptOfTheWeekCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-send-push-prompt-of-the-week {app_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send Push notification of prompt of the week for each user.';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // $company = Companies::getById((int) $this->argument('company_id'));
        $messageTypeId = (int) $this->argument('message_type_id');

        $messageType = MessageType::getById($messageTypeId);
        $via = [
            NotificationChannelEnum::getNotificationChannelBySlug('push'),
        ];
        UsersAssociatedApps::fromApp($app)
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->chunk(100, function ($usersAssocApps) use ($app, $messageType, $via) {
                foreach ($usersAssocApps as $usersAssocApp) {
                    $this->info('Sending message of the week to user: ' . $usersAssocApp->user->getId());
                    SendMessageOfTheWeekJob::dispatch($app, $usersAssocApp->user, $messageType, $via);
                    $this->info('Message of the week sent to user: ' . $usersAssocApp->user->getId());
                }
            });

        return;
    }
}
