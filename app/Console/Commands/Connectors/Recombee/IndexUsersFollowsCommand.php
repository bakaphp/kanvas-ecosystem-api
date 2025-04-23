<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class IndexUsersFollowsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:recombee-index-users-follows {app_id} {companies_id} {message_types_id}';
    protected $description = 'Index users follows to the recommendation engine';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::find($this->argument('companies_id'));
        $messageType = MessageType::find($this->argument('message_types_id'));
        // $this->overwriteAppService($app);

        $query = UsersFollows::fromApp($app)
        ->where('is_deleted', 0)
        ->where('entity_namespace', Users::class)
        ->orderBy('id', 'DESC');
        $cursor = $query->cursor();
        $totalTags = $query->count();

        $this->output->progressStart($totalTags);
        $usersFollowsIndex = new RecombeeIndexService(
            $app,
            (string) $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            (string) $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );
        $usersFollowsIndex->createUsersFollowsItemsDatabase();

        foreach ($cursor as $userFollow) {
            $result = $usersFollowsIndex->indexUsersFollows($userFollow, $company, $messageType);

            $this->info('Users Follows ID: '.$userFollow->getId().' indexed with result: '.$result);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

    }
}
