<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Users;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Exception;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Repositories\UsersRepository;

class KanvasUserAddToCompany extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:users {apps_id} {email} {branch_id} {role?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add User to Company';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        Bouncer::scope()->to(RolesEnums::getScope($app));

        $email = $this->argument('email');
        $branchId = $this->argument('branch_id');
        $role = RolesRepository::getByMixedParamFromCompany($this->argument('role') ?? RolesEnums::ADMIN->value);

        $branch = CompaniesBranches::findOrFail($branchId);
        $company = $branch->company()->first();
        $company->associateApp($app);
        $user = UsersRepository::getByEmail($email);

        $assignCompanyAction = new AssignCompanyAction(
            $user,
            $branch,
            $role,
            $app
        );
        $assignCompanyAction->execute();

        //make sure it has the app profile
        try {
            $user->getAppProfile($app);
        } catch (Exception $e) {
            $this->error('User didn\'t exist in this app, we just created it , run it again  ' . $e->getMessage());
        }

        $this->newLine();
        $this->info("User {$email} successfully added to branch : " . $branch->name . ' ( ' . $branch->getKey() . ') in app  ' . $app->name);
        $this->newLine();
    }
}
