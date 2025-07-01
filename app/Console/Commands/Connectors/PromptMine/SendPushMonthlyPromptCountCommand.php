<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Jobs\SendMonthlyMessageCountJob;
use Kanvas\Connectors\PromptMine\Repositories\MessagesRepository;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\UsersAssociatedApps;

class SendPushMonthlyPromptCountCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-send-push-monthly-prompt-count {app_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send Push notification of montly count of prompts for each user.';

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
                    $monthtlyCount = MessagesRepository::getcurrentMonthCreationCount($app, $usersAssocApp->user, $messageType);
                    if ($monthtlyCount === 0) {
                        $this->info("User {$usersAssocApp->user->getId()} has no messages this month.");
                        continue;
                    }
                    $this->info("User {$usersAssocApp->user->getId()} has $monthtlyCount messages this month.");
                    $this->info("Sending push notification to user {$usersAssocApp->user->getId()}");
                    SendMonthlyMessageCountJob::dispatch($app, $usersAssocApp->user, $monthtlyCount, $messageType, $via);
                    $this->info("Push notification sent to user {$usersAssocApp->user->getId()}");
                }
            });

        return;
    }
}
