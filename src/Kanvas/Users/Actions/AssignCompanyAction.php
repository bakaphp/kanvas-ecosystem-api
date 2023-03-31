<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Bouncer;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class AssignCompanyAction
{
    public Users $user;
    public Companies $company;
    public CompaniesBranches $branch;
    public DefaultRoles $role;

    public function __construct(Users $user, CompaniesBranches $branch, ?DefaultRoles $role = null)
    {
        $this->user = $user;
        $this->company = $branch->company()->first();
        $this->branch = $branch;
        $this->role = $role ?? DefaultRoles::ADMIN;
    }

    /**
     * execute
     */
    public function execute(): void
    {
        $app = app(Apps::class);
        // $branch = $this->company->branch()->first();
        if (! $this->user->get(Companies::cacheKey())) {
            $this->user->set(Companies::cacheKey(), $this->company->id);
        }

        if (! $this->user->get($this->company->branchCacheKey())) {
            $this->user->set($this->company->branchCacheKey(), $this->branch->id);
        }

        $this->company->associateUser(
            $this->user,
            StateEnums::ON->getValue(),
            $this->branch
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
            $assignRole = new AssignAction($this->user, $this->role::ADMIN->getValue());
            $assignRole->execute();
        }

        if (! $roleLegacy = $app->get(AppSettingsEnums::DEFAULT_ROLE_NAME->getValue())) {
            $roleLegacy = $app->name . '.' . $this->user->role()->first()->name;
        }

        $assignRoleLegacy = new AssignRole($this->user, $this->company, $app);
        $assignRoleLegacy->execute($roleLegacy);
    }
}
