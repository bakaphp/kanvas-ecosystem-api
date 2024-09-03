<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Users;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\RequestDeletedAccount;

class DeleteUsersRequestedCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:user:delete {apps_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Delete a user';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $appsId = $this->argument('apps_id');
        if ($appsId) {
            $app = Apps::findFirstOrFail($appsId);
            $this->overwriteAppService($app);
            $this->info('Deleting user from app: ' . $app->name);
        }

        if (! $app->get('delete_users_request_process')) {
            $this->info('Delete users request process is disabled');

            return;
        }

        $days = $appsId ? $app->get('days_to_delete') : 30;
        
        $users = RequestDeletedAccount::when($appsId, function ($query) use ($appsId) {
            return $query->where('apps_id', $appsId);
        })->where(DB::raw('DATEDIFF(request_date, CURDATE())'), '>', $days)
                ->where('is_deleted', 0)
                ->get();

        foreach ($users as $user) {
            echo 'Deleting user: ' . $user->email . PHP_EOL;
            $user->associateUsers()->deActive();
        }
    }
}
