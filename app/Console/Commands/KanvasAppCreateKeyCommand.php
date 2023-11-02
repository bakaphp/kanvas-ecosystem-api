<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class KanvasAppCreateKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:app-key {name} {app_uuid} {email}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Kanvas App Key';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $appUid = $this->argument('app_uuid');

        $app = Apps::getByUuid($appUid);
        $user = Users::getByEmail($email);

        UsersRepository::belongsToThisApp($user, $app);

        $appKey = (
            new CreateAppKeyAction(
                new AppKeyInput(
                    $name,
                    $app,
                    $user
                )
            )
        )->execute();

        $this->newLine();
        $this->info('App Key created successfully: ' . $appKey->client_id);
        $this->newLine();
        $this->info('Secret: ' . $appKey->client_secret_id);

        return;
    }

    /**
     * allow me to reassign the roles to the user.
     */
    protected function resyncAppRoles(Apps $app)
    {
        Bouncer::scope()->to(RolesEnums::getScope($app));

        foreach ($app->keys() as $key) {
            $key->user->assign(RolesEnums::OWNER->value);
            $key->user->assign(RolesEnums::ADMIN->value);
        }
    }
}
