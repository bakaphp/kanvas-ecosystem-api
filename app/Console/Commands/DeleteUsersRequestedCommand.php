<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Users\Models\RequestDeletedAccount;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;

class DeleteUsersRequestedCommand extends Command
{
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
            $this->info('Deleting user from app: ' . $app->name);
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
