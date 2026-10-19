<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\BouncerFacade as Bouncer;

class KanvasAppCreateKeyCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:app-key {name} {app_id} {email}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Kanvas App Key';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $appId = $this->argument('app_id');

        $app = Apps::getById($appId);
        $this->overwriteAppService($app);
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

        // A key from this command is the platform granting app ownership; keys made elsewhere carry
        // no role of their own.
        Bouncer::scope()->onceTo(RolesEnums::getScope($app), fn () => $user->assign(RolesEnums::OWNER->value));

        $this->newLine();
        $this->info('App Key created successfully: ' . $appKey->client_id);
        $this->newLine();
        $this->info('Secret: ' . $appKey->client_secret_id);

        return;
    }
}
