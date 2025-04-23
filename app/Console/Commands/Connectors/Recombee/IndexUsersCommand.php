<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class IndexUsersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:recombee-index-users {app_id} {companies_id}';

    protected $description = 'Index users to the recommendation engine';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::find($this->argument('companies_id'));

        $query = UsersAssociatedApps::fromApp($app)
            ->where('is_deleted', 0)
            ->where('companies_id', $company->getId())
            ->where('user_active', 1)
            ->orderBy('id', 'DESC');
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $usersIndex = new RecombeeIndexService(
            $app
        );
        $usersIndex->createUsersDatabase();

        foreach ($cursor as $userAssociatedApp) {
            if (!$userAssociatedApp->user instanceof Users) {
                continue;
            }

            $result = $usersIndex->indexUsers($userAssociatedApp->user, $company);

            $this->info('Message ID: '.$userAssociatedApp->user->getId().' indexed with result: '.$result);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

    }
}
