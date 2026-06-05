<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\BouncerFacade as Bouncer;

use function Laravel\Prompts\info;

class AssignRoleToUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:assign-role {user} {role} {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Assign a role to a user for a specific app. {user} is an email or user id, {role} is a role name or id.';

    public function handle(): int
    {
        $userArg = $this->argument('user');
        $roleArg = $this->argument('role');
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));

        Bouncer::scope()->to(RolesEnums::getScope($app));

        $user = is_numeric($userArg)
            ? UsersRepository::getUserOfAppById((int) $userArg, $app)
            : UsersRepository::getUserOfAppByEmail($userArg, $app);

        $role = RolesRepository::getByMixedParamFromCompany(
            param: is_numeric($roleArg) ? (int) $roleArg : $roleArg,
            app: $app,
        );

        new AssignRoleAction($user, $role, $app)->execute();

        info("Assigned role '{$role->name}' to user '{$user->email}' (ID: {$user->getId()}) for app '{$app->name}' (ID: {$app->getId()}).");

        return self::SUCCESS;
    }
}
