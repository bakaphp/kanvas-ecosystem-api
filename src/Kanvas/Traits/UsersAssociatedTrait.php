<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\UsersGroup\Users\Models\Users;

trait UsersAssociatedTrait
{
    /**
     * create new related User Associated instance dynamically.
     *
     * @param Users $user
     * @param Companies $company
     *
     * @return array
     *
     * @todo Find a better way to handle namespaces for models
     */
    public function associate(Users $user, Companies $company) : array
    {
        $app = app(Apps::class);
        $class = str_replace('UsersAssociated\\', 'UsersAssociated', substr_replace(get_class($this), '\UsersAssociated', strrpos(get_class($this), '\\'), 0));
        $usersAssociatedModel = new $class();
        $usersAssociatedModel->users_id = $user->getKey();
        $usersAssociatedModel->companies_id = $company->getKey();
        $usersAssociatedModel->apps_id = get_class($this) == Apps::class ? $this->getKey() : $app->getKey();
        $usersAssociatedModel->identify_id = (string) $user->getKey();
        $usersAssociatedModel->user_active = 1;
        $usersAssociatedModel->user_role = (string) $user->roles_id;
        $usersAssociatedModel->created_at = date('Y-m-d H:i:s');
        $usersAssociatedModel->save();

        return $usersAssociatedModel->toArray();
    }
}
