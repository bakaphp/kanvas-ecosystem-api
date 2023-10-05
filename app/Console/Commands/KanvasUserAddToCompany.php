<?php

namespace App\Console\Commands;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Enums\DefaultRoles;
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
        $role = $this->argument('role') ?? DefaultRoles::ADMIN;

        $branch = CompaniesBranches::findOrFail($branchId);
        $company = $branch->company()->first();
        $company->associateApp($app);

        $assignCompanyAction = new AssignCompanyAction(
            UsersRepository::getByEmail($email),
            $branch,
            $role,
            $app
        );
        $assignCompanyAction->execute();

        $this->newLine();
        $this->info("User {$email} successfully added to branch : " . $branch->name . ' ( ' . $branch->getKey() . ') in app  ' . $app->name);
        $this->newLine();
    }
}
