<?php

declare(strict_types=1);

namespace Kanvas\Users\AssociatedCompanies\Actions;

use Kanvas\Apps\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Users\AssociatedCompanies\Models\UsersAssociatedCompanies;
use Kanvas\Users\Users\Models\Users;

class AssociateUsersCompaniesAction
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
     * @param AppsPostData $data
     *
     * @return Apps
     */
    public function execute() : array
    {
        $usersAssociatedModel = new UsersAssociatedCompanies();
        $usersAssociatedModel->users_id = $this->user->getKey();
        $usersAssociatedModel->companies_id = $this->company->getKey();
        $usersAssociatedModel->identify_id = (string) $this->user->getKey();
        $usersAssociatedModel->user_active = 1;
        $usersAssociatedModel->user_role = (string) $this->user->roles_id;
        $usersAssociatedModel->created_at = date('Y-m-d H:i:s');
        $usersAssociatedModel->save();

        return (array)$usersAssociatedModel;
    }
}
