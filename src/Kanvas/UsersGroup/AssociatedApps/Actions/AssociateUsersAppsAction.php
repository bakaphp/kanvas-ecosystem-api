<?php

declare(strict_types=1);

namespace Kanvas\UsersGroup\AssociatedApps\Actions;

use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\UsersGroup\AssociatedApps\Models\UsersAssociatedApps;
use Kanvas\UsersGroup\Users\Models\Users;

class AssociateUsersAppsAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user,
        protected Companies $company,
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Apps
     */
    public function execute() : array
    {
        $app = app(Apps::class);
        $usersAssociatedModel = new UsersAssociatedApps();
        $usersAssociatedModel->users_id = $this->user->getKey();
        $usersAssociatedModel->companies_id = $this->company->getKey();
        $usersAssociatedModel->apps_id = $app->getKey();
        $usersAssociatedModel->identify_id = (string) $this->user->getKey();
        $usersAssociatedModel->user_active = 1;
        $usersAssociatedModel->user_role = (string) $this->user->roles_id;
        $usersAssociatedModel->created_at = date('Y-m-d H:i:s');
        $usersAssociatedModel->save();

        return (array)$usersAssociatedModel;
    }
}
