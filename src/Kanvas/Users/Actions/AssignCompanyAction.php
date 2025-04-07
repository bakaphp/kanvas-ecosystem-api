<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Jobs\CompanyDashboardJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Actions\AssignRoleAction as ActionsAssignRoleAction;
use Kanvas\Users\Models\Users;

class AssignCompanyAction
{
    public Companies $company;
    public Role $role;
    public Apps $app;

    public function __construct(
        public Users $user,
        public CompaniesBranches $branch,
        ?Role $role = null,
        ?Apps $app = null
    ) {
        $this->user = $user;
        $this->company = $branch->company()->firstOrFail();
        $this->branch = $branch;
        $this->role = $role ?? RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value, $this->company);
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
            $this->role
        );
        $assignRole->execute();

        /**
         * @todo after migration to niche, remove
         */
        if ($app->get(AppSettingsEnums::USE_LEGACY_ROLES->getValue(), false)) {
            if (! $roleLegacy = $app->get(AppSettingsEnums::DEFAULT_ROLE_NAME->getValue())) {
                $roleLegacy = $app->name . '.' . $this->user->role()->notDeleted()->first()->name;
            }

            $assignRoleLegacy = new ActionsAssignRoleAction($this->user, $this->company, $app);
            $assignRoleLegacy->execute($roleLegacy);
        }

        CompanyDashboardJob::dispatch($this->company, $app);
    }
}
