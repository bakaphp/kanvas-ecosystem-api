<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UserRoleRepository;

use function Sentry\captureException;

class AddAdminsToCompanyAction
{
    public function __construct(
        public AppInterface $app,
        public Users $authUser,
        public Companies $company,
        public CompaniesBranches $branch
    ) {
    }

    public function execute(): void
    {
        if ($b2bCompany = B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->company)) {
            try {
                $admins = $this->getAdmins($this->app);
                $addUserCompanyAction = new AddUserCompanyAction(
                    $this->authUser,
                    $this->company,
                    $this->app,
                    $this->branch
                );

                $role = RolesRepository::getByNameFromCompany(
                    name: RolesEnums::ADMIN->value,
                    app: $this->app
                );

                $addUserCompanyAction->execute($admins, $role->id);
            } catch (Exception $e) {
                captureException($e);
            }
        }
    }

    public function getAdmins(
        AppInterface $app
    ): Collection {
        $role = RolesRepository::getByNameFromCompany(
            name: RolesEnums::ADMIN->value,
            app: $app
        );

        return UserRoleRepository::getAllUsersOfRole($role, $app)->get();
    }
}
