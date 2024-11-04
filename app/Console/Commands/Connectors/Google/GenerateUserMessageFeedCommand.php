<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Enums\UserEventEnum;
use Kanvas\Connectors\Google\Services\DiscoveryUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
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
                $userForYouFeedRecommendation = $userRecommendation->getRecommendation(
                    $user,
                    UserEventEnum::VIEW_HOME_PAGE,
                    $pageSize
                );
                $userForYouFeed = iterator_to_array($userForYouFeedRecommendation->getResults()->getIterator());

                if ($cleanUserFeed) {
                    UserMessage::fromApp($app)->where('users_id', $user->getId())->delete();
                }

                $totalSeconds = 200;
                $secondsInterval = $totalSeconds / count($userForYouFeed);

                foreach ($userForYouFeed as $index => $message) {
                    if (Message::fromApp($app)->where('id', $message->getId())->count() == 0) {
                        continue;
                    }

                    $userMessage = new UserMessage();
                    $userMessage->messages_id = $message->getId();
                    $userMessage->users_id = $user->getId();
                    $userMessage->apps_id = $app->getId();

                    // Add seconds based on index - this will spread messages over the time period
                    $userMessage->created_at = Carbon::now()->subSeconds($totalSeconds - ($index * $secondsInterval));

                    $userMessage->saveOrFail();
                }

                $processedUsers++;
                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine();
        $this->info('Successfully generated feed for ' . $processedUsers . ' users');
    }
}
