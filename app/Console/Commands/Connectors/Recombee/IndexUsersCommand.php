<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Users\Models\UsersAssociatedApps;
use Recombee\RecommApi\Client;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class IndexUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:recombee-index-users {app_id} {companies_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index users to the recommendation engine';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::find($this->argument('companies_id'));
        // $this->overwriteAppService($app);

        $query = UsersAssociatedApps::fromApp($app)
            ->where('is_deleted', 0)
            ->where('companies_id', $company->getId())
            ->where('user_active', 1)
            ->orderBy('id', 'DESC');
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $usersIndex = new RecombeeIndexService(
            $app,
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );
        $usersIndex->createUsersDatabase();


        foreach ($cursor as $userAssociatedApp) {
            $result = $usersIndex->indexUsers($userAssociatedApp->user, $company);

            $this->info('Message ID: ' . $userAssociatedApp->user->getId() . ' indexed with result: ' . $result);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        return;
    }
}
