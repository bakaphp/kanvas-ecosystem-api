<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Actions\AssignRoleAction as ActionsAssignRoleAction;
use Kanvas\Users\Models\Users;

class AssignCompanyAction
{
    public Users $user;
    public Companies $company;
    public CompaniesBranches $branch;
    public DefaultRoles $role;
    public Apps $app;

    public function __construct(
        Users $user,
        CompaniesBranches $branch,
        ?DefaultRoles $role = null,
        ?Apps $app = null
    ) {
        $this->user = $user;
        $this->company = $branch->company()->firstOrFail();
        $this->branch = $branch;
        $this->role = $role ?? DefaultRoles::ADMIN;
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute
     */
    public function execute(): void
    {
        $app = $this->app;
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

        $userAssociatedAppCompany = $this->company->associateUserApp(
            $this->user,
            $app,
            StateEnums::ON->getValue()
        );

        $assignRole = new AssignRoleAction(
            $userAssociatedAppCompany,
            RolesRepository::getByNameFromCompany($this->role->getValue()),
        );
        $assignRole->execute();

        if (! $roleLegacy = $app->get(AppSettingsEnums::DEFAULT_ROLE_NAME->getValue())) {
            $roleLegacy = $app->name . '.' . $this->user->role()->first()->name;
        }

        $assignRoleLegacy = new ActionsAssignRoleAction($this->user, $this->company, $app);
        $assignRoleLegacy->execute($roleLegacy);
    }
}
