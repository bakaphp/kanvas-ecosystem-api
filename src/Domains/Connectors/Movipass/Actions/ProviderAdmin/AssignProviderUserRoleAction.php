<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\ProviderAdmin;

use Bouncer;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class AssignProviderUserRoleAction
{
    public function __construct(
        protected readonly Users $actor,
        protected readonly Users $target,
        protected readonly Companies $company,
        protected readonly MovipassRolesEnum $role,
        protected readonly Apps $app,
    ) {
    }

    public function execute(): Role
    {
        return ProviderAdminGuard::inAppScope($this->app, function (): Role {
            ProviderAdminGuard::assertCompanyInApp($this->company, $this->app);
            ProviderAdminGuard::assertMayManageTarget($this->actor, $this->target, $this->role, $this->app);

            $role = ProviderAdminGuard::roleFor($this->role, $this->app);

            $this->attachToCompany($role);

            new AssignRoleAction($this->target, $role, $this->app)->execute();
            Bouncer::refreshFor($this->target);

            return $role;
        });
    }

    protected function attachToCompany(Role $role): void
    {
        $branch = $this->company->defaultBranch()->first();

        if ($branch === null) {
            throw new ValidationException("Company {$this->company->name} has no default branch.");
        }

        $this->company->associateUser($this->target, StateEnums::YES->getValue(), $branch, (int) $role->id);
        $this->company->associateUserApp($this->target, $this->app, StateEnums::YES->getValue(), (int) $role->id);

        UsersAssociatedCompanies::query()
            ->where('users_id', $this->target->getId())
            ->where('companies_id', $this->company->getId())
            ->update(['user_role' => $role->id]);

        UsersAssociatedApps::query()
            ->where('users_id', $this->target->getId())
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->app->getId())
            ->update(['user_role' => $role->id]);
    }
}
