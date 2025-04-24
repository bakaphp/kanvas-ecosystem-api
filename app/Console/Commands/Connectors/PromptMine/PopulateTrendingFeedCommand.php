<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Recombee\Actions\PopulateTrendingFeedAction;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag;

class PopulateTrendingFeedCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:prompt-generate-trending-feed {app_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Generate tags for all messages in google recommendation';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        //$messageType = (int) $this->argument('message_type_id');

        //$messageType = MessageType::getById($messageType, $app);

        $populateTrendingFeedAction = new PopulateTrendingFeedAction($app, $company, true);
        $populateTrendingFeedAction->execute();

        /*  $tag = (new CreateTagAction(
             new Tag(
                 $app,
                 $company->user,
                 $company,
                 'trending'
             )
         ))->execute();
         $tag->name = 'Trending';
         $tag->is_feature = 1;
         $tag->saveOrFail(); */

        return;
    }
}
