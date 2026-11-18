<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\ProviderAdmin;

use Bouncer;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class RemoveProviderUserRoleAction
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

            Bouncer::retract($role->name)->from($this->target);
            $this->clearCompanyRole($role);
            Bouncer::refreshFor($this->target);

            return $role;
        });
    }

    protected function clearCompanyRole(Role $role): void
    {
        UsersAssociatedCompanies::query()
            ->where('users_id', $this->target->getId())
            ->where('companies_id', $this->company->getId())
            ->where('user_role', $role->id)
            ->update(['user_role' => null]);

        UsersAssociatedApps::query()
            ->where('users_id', $this->target->getId())
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->app->getId())
            ->where('user_role', $role->id)
            ->update(['user_role' => null]);
    }
}
