<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Bouncer;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Actions\AssignRole;
use Kanvas\Apps\Models\Apps;
use Kanvas\AccessControlList\Models\Role;

class AssignCompanyAction
{
    public Users $user;
    public Companies $company;

    public function __construct(string $email, int $companiesId)
    {
        $this->user = UsersRepository::getByEmail($email);
        $this->company = CompaniesRepository::getById($companiesId);
    }

    /**
     * execute
     */
    public function execute(): void
    {    
        $app = app(Apps::class);
        $branch = $this->company->branch()->first();
        if (! $this->user->get(Companies::cacheKey())) {
            $this->user->set(Companies::cacheKey(), $this->company->id);
        }

        if (! $this->user->get($this->company->branchCacheKey())) {
            $this->user->set($this->company->branchCacheKey(), $branch->id);
        }

        $this->company->associateUser(
            $this->user,
            StateEnums::ON->getValue(),
            $branch
        );


        $this->company->associateUserApp(
            $this->user,
            $app,
            StateEnums::ON->getValue()
        );
        Bouncer::scope()->to(RolesRepository::getScope($this->user));
        if ($this->user->roles_id) {
            $role = Role::find($this->user->roles_id)->name;
            $assignRole = new AssignAction($this->user, $role);
            $assignRole->execute();
        } else {
            $assignRole = new AssignAction($this->user, DefaultRoles::ADMIN->getValue());
            $assignRole->execute();
        }

        if (!$roleLegacy = $app->get(AppSettingsEnums::DEFAULT_ROLE_NAME->getValue())) {
            $roleLegacy = $app->name . '.' . $this->user->role()->first()->name;
        }

        $assignRoleLegacy = new AssignRole($this->user, $this->company, $app);
        $assignRoleLegacy->execute($roleLegacy);
    }
}
