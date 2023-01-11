<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Baka\Http\Exception\InternalServerErrorException;
use Baka\Http\Exception\UnauthorizedException;
use Kanvas\Apps\Enums\Defaults as AppsDefaults;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Roles\Models\Roles;
use Kanvas\Roles\Repositories\RolesRepository;
use Kanvas\Users\Models\UserRoles;
use Baka\Support\Str;

trait PermissionsTrait
{
    /**
     * Assigned a user this role
     * Example: App.Role.
     *
     * @param string $role
     * @param Companies|null $company
     *
     * @return bool
     */
    public function assignRole(string $role, ?Companies $company = null) : bool
    {
        /**
         * check if we have a dot, that means it legacy and sending the app name
         * not needed any more so we remove it.
         */
        if (Str::contains($role, '.')) {
            $appRole = explode('.', $role);
            $role = $appRole[1];
        }

        $company = $company !== null ? $company : $this->defaultCompany();
        $role = RolesRepository::getByName($role, $company);

        $app = app(Apps::class);
        $appId = $role->apps_id == AppsDefaults::CANVAS_DEFAULT_APP_ID->getValue() ? $app->getKey() : $role->apps_id;


        $userRole = UserRoles::where('users_id', $this->getKey())
                    ->where('apps_id', $appId)
                    ->where('companies_id', $company->getKey())
                    ->first();

        if (!$userRole) {
            $userRole = new UserRoles();
            $userRole->users_id = $this->getKey();
            $userRole->roles_id = $role->getKey();
            $userRole->apps_id = $appId;
            $userRole->companies_id = $company->getKey();
        }

        return $userRole->save();
    }

    /**
     * Assigned a user this role
     * Example: App.Role.
     *
     * @param int $id
     * @param Companies|null $company
     *
     * @return bool
     */
    public function assignRoleById(int $id, ?Companies $company = null) : bool
    {
        $company = $company !== null ? $company : $this->defaultCompany();
        $role = Roles::getById($id, $company);

        $userRole = UserRoles::findFirstOrCreate([
            'conditions' => 'users_id = :users_id:
                            AND apps_id = :apps_id:
                            AND companies_id = :companies_id:
                            AND is_deleted = 0',
            'bind' => [
                'users_id' => $this->getId(),
                'apps_id' => $role->getAppsId(),
                'companies_id' => $company->getId()
            ]], [
                'users_id' => $this->getId(),
                'roles_id' => $role->getId(),
                'apps_id' => $role->getAppsId(),
                'companies_id' => $company->getId()
            ]);

        $userRole->roles_id = $role->getId();

        return $userRole->saveOrFail();
    }

    /**
     * Remove a role for the current user
     * Example: App.Role.
     *
     * @param string $role
     * @param Companies|null $company
     *
     * @return bool
     */
    public function removeRole(string $role, ?Companies $company = null) : bool
    {
        $company = $company !== null ? $company : $this->defaultCompany();
        $role = Roles::getByAppName($role, $company);

        $userRole = UserRoles::findFirst([
            'conditions' => 'users_id = ?0
                            AND roles_id = ?1
                            AND apps_id = ?2
                            AND companies_id = ?3',
            'bind' => [
                $this->getId(),
                $role->getId(),
                $role->apps_id,
                $company->getId()
            ]
        ]);

        if (is_object($userRole)) {
            return $userRole->delete();
        }

        return false;
    }

    /**
     * Check if the user has this role.
     *
     * @param string $role
     * @param Companies|null $company
     *
     * @return bool
     */
    public function hasRole(string $role, ?Companies $company = null) : bool
    {
        $company = $company !== null ? $company : $this->defaultCompany();
        $role = Roles::getByAppName($role, $company);

        return (bool) UserRoles::count([
            'conditions' => 'users_id = ?0
                            AND roles_id = ?1
                            AND (apps_id = ?2 or apps_id = ?4)
                            AND companies_id = ?3',
            'bind' => [
                $this->getId(),
                $role->getId(),
                $role->apps_id,
                $company->getId(),
                $this->di->getApp()->getId()
            ]
        ]);
    }

    /**
     * At this current system / app can you do this?
     *
     * Example: resource.action
     *  Leads.add || leads.updates || lead.delete
     *
     * @param string $action
     * @param bool $throwException
     *
     * @return bool
     */
    public function can(string $action, bool $throwException = false) : bool
    {
        //we expect the can to have resource.action
        if (!Str::contains($action, '.')) {
            throw new InternalServerErrorException('ACL - We are expecting the resource for this action');
        }

        $action = explode('.', $action);
        $resource = $action[0];
        $action = $action[1];

        //get your user account role for this app or the canvas ecosystem
        if (!$role = $this->getPermission()) {
            throw new InternalServerErrorException(
                'ACL - User doesn\'t have any set roles in this current app ' . $this->getDI()->get('app')->name
            );
        }

        $canExecute = $this->getDI()->get('acl')->isAllowed($role->roles->name, $resource, $action);

        if ($throwException && !$canExecute) {
            throw new UnauthorizedException("ACL - Users doesn't have permission to run this action `{$action}`");
        }

        return (bool) $canExecute;
    }

    /**
     * Check if user is admin.
     *
     * @param bool $throw
     *
     * @return bool
     */
    public function isAdmin(bool $throw = true) : bool
    {
        if (!$this->hasRole("{$this->getDI()->get('app')->name}.Admins")) {
            if ($throw) {
                throw new UnauthorizedException('Current user does not have Admins role');
            }

            return false;
        }

        return true;
    }
}
