<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Models\UsersAssociatedApps;

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

        $users = UsersAssociatedApps::fromApp($app)->notDeleted()->get();
        foreach ($users as $user) {
            $userRegisterInApp = new RegisterUsersAppAction(
                $user,
                $app
            );
            $userRegisterInApp->execute($user->password);
        }

        return;
    }
}
