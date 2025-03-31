<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Jobs\SendMonthlyMessageCountJob;
use Kanvas\Connectors\PromptMine\Repositories\MessagesRepository;
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
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // $company = Companies::getById((int) $this->argument('company_id'));
        $messageTypeId = (int) $this->argument('message_type_id');

        $messageType = MessageType::getById($messageTypeId);

        UsersAssociatedApps::fromApp($app)
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->chunk(100, function ($users) use ($app, $messageType) {
                foreach ($users as $user) {
                    $monthtlyCount = MessagesRepository::getcurrentMonthCreationCount($app, $user, $messageType);
                    (new SendMonthlyMessageCountJob($app, $user, $monthtlyCount, $messageType, [
                        'via' => 'push',
                    ]))::dispatch();
                }
            });

        return;
    }
}
