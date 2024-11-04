<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Enums\UserEventEnum;
use Kanvas\Connectors\Google\Services\DiscoveryUserRecommendationService;
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
        $pageSize = $this->argument('page_size') ?? 350;
        $cleanUserFeed = $this->argument('clean_user_feed') ?? false;

        $this->info('Generating user message feed for app: ' . $this->argument('app_id'));
        $userRecommendation = new DiscoveryUserRecommendationService($app, $company);

        //navigate all user of the app
        UserAppRepository::getAllAppUsers($app)->chunk(100, function ($users) use ($app, $company, $userRecommendation, $pageSize, $cleanUserFeed) {
            foreach ($users as $user) {
                $userForYouFeedRecommendation = $userRecommendation->getRecommendation($user, UserEventEnum::VIEW_HOME_PAGE, $pageSize);
                $userForYouFeed = array_reverse(iterator_to_array($userForYouFeedRecommendation->getResults()->getIterator()));

                //clean user feed
                if($cleanUserFeed) {
                    
                }
            }
        });
    }
}
