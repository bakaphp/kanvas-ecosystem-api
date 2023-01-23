<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Roles\Repositories\RolesRepository;
use Kanvas\Users\Models\UserRoles;
use Kanvas\Users\Models\Users;

class AssignRole
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user,
        protected Companies $company,
        protected Apps $app
    ) {
    }

    /**
     * Invoke function.
     *
     * @param RegisterInput $data
     *
     * @return Users
     */
    public function execute(string $roleName) : UserRoles
    {
        /**
         * check if we have a dot, that means it legacy and sending the app name
         * not needed any more so we remove it.
         */
        if (Str::contains($roleName, '.')) {
            $appRole = explode('.', $roleName);
            $roleName = $appRole[1];
        }

        $role = RolesRepository::getByName(
            $roleName,
            $this->app,
            $this->company
        );

        $userRole = UserRoles::firstOrCreate([
            'users_id' => $this->user->id,
            'apps_id' => $this->app->id,
            'companies_id' => $this->company->id
        ], [
            'users_id' => $this->user->id,
            'roles_id' => $role->id,
            'apps_id' => $this->app->id,
            'companies_id' => $this->company->id
        ]);

        $userRole->roles_id = $role->getKey();
        $userRole->saveOrFail();

        return $userRole;
    }
}
