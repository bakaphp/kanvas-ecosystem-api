<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Arr;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\AbilityEnum;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\Models\Users;

class UserManagement
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user,
        protected AppInterface $app,
        protected ?Users $userEditing = null
    ) {
    }

    /**
     * Update current user data with $data
     */
    public function update(array $data): Users
    {
        try {
            $customFields = null;
            $files = null;
            $customFields = Arr::pull($data, 'custom_fields', []);
            $files = Arr::pull($data, 'files', []);
            $roleIds = Arr::pull($data, 'role_ids', []);

            $userAppProfile = $this->user->getAppProfile($this->app);

            //@todo when we update the login to use userAssociatedApps we need to remove this
            $this->user->update($data);
            $userAppProfile->update($data);

            if ($customFields) {
                $this->user->setAll($customFields, true);
            }

            if ($files) {
                $this->user->addMultipleFilesFromUrl($files);
            }

            //update roles if
            $this->updateRole($roleIds);
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        return $this->user;
    }

    protected function updateRole(array $roleIds): void
    {
        if (! empty($roleIds) && $this->userEditing) {
            $updateRole = $this->userEditing->isAdmin() || $this->userEditing->can(AbilityEnum::MANAGE_ROLES->value);

            if (! $updateRole) {
                return;
            }
            foreach ($roleIds as $roleId) {
                $role = RolesRepository::getByMixedParamFromCompany(
                    param: $roleId,
                    app: $this->app
                );

                $assign = new AssignRoleAction(
                    $this->user,
                    $role
                );
                $assign->execute();
            }
        }
    }
}
