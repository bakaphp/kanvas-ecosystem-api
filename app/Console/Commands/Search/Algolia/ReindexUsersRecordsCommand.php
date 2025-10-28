<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class ReindexUsersRecordsCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-social:reindex-users-records {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Reindex social users records by app';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->reindex($app);

        return;
    }

    public function reindex(Apps $app)
    {
        $this->info('Reindex scout index for user App ' . $app->name);
        $users = DB::table('users')
            ->join('users_associated_apps', 'users.id', '=', 'users_associated_apps.users_id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.user_active', 1)
            ->where('companies_id', 0)
            ->where('users.is_deleted', 0)
            ->get();

        $this->info('Total users to reindexed: ' . $users->count());
        $users->searchable();
    }
}
