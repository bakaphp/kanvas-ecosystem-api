<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Users;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Models\UsersAssociatedApps;
use Throwable;

class KanvasAppUserMigration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:user-migration {app_uuid}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Migrate legacy users to new kanvas niche structure';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $appUid = $this->argument('app_uuid');
        $app = Apps::getByUuid($appUid);

        $users = UsersAssociatedApps::fromApp($app)
                ->notDeleted()
                ->where('companies_id', '<>', 0)
                ->groupBy('users_id', 'apps_id', 'companies_id')
                ->orderBy('users_id', 'desc')
                ->get();

        foreach ($users as $user) {
            try {
                $userData = $user->user()->firstOrFail();
                $userRegisterInApp = new RegisterUsersAppAction(
                    $userData,
                    $app
                );
                $userRegisterInApp->execute($userData->password);
            } catch(Throwable $e) {
                $this->error('Error creating user : ' . $user->user_id . ' ' . $e->getMessage());
            }
        }

        return;
    }
}
