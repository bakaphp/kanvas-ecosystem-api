<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Actions\GenerateGoogleUserMessageAction;
use Kanvas\Connectors\Google\Services\DiscoveryUserRecommendationService;
use Kanvas\Users\Repositories\UserAppRepository;

class GenerateUserMessageFeedCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:google-generate-user-message-feed {app_id} {company_id} {page_size} {clean_user_feed?}';

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
        $userRecommendation = new DiscoveryUserRecommendationService($app, $company);

        // Get total count for progress bar
        $totalUsers = UserAppRepository::getAllAppUsers($app)->count();
        $processedUsers = 0;
        $progress = $this->output->createProgressBar($totalUsers);
        $progress->start();

        UserAppRepository::getAllAppUsers($app)->chunk(100, function ($users) use (
            $app,
            $company,
            $userRecommendation,
            $pageSize,
            $cleanUserFeed,
            &$processedUsers,
            $progress
        ) {
            foreach ($users as $user) {
                $generateUserMessage = new GenerateGoogleUserMessageAction(
                    $app,
                    $company,
                    $user,
                    $cleanUserFeed
                );
                $generateUserMessage->execute($pageSize);

                $processedUsers++;
                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine();
        $this->info('Successfully generated feed for ' . $processedUsers . ' users');
    }
}
