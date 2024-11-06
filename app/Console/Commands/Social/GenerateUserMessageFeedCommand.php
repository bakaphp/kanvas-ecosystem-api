<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Interactions\Jobs\GenerateUserMessageJob;
use Kanvas\Users\Repositories\UserAppRepository;

class GenerateUserMessageFeedCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-social:generate-user-message-feed {app_id} {company_id} {page_size} {clean_user_feed?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Using Google recommendations, generate a user message feed for a specific app';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $pageSize = (int) ($this->argument('page_size') ?? 350);
        $cleanUserFeed = $this->argument('clean_user_feed') ?? false;

        $this->info('Generating user message feed for app: ' . $app->getId());

        // Get total count for progress bar
        $totalUsers = UserAppRepository::getAllAppUsers($app)->count();
        $processedUsers = 0;
        $progress = $this->output->createProgressBar($totalUsers);
        $progress->start();

        UserAppRepository::getAllAppUsers($app)->chunk(100, function ($users) use (
            $app,
            $company,
            $cleanUserFeed,
            &$processedUsers,
            $progress
        ) {
            foreach ($users as $user) {
                GenerateUserMessageJob::dispatch($app, $company, $user);
                $processedUsers++;
                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine();
        $this->info('Successfully generated feed for ' . $processedUsers . ' users');
    }
}
