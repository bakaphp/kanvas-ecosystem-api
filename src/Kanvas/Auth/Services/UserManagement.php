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
            $roleId = Arr::pull($data, 'role_id');

            $this->user->update(array_filter($data));

            if ($customFields) {
                $this->user->setAll($customFields);
            }

            if ($files) {
                $this->user->addMultipleFilesFromUrl($files);
            }
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        //update roles if
        $this->updateRole($roleId);

        return $this->user;
    }

    protected function updateRole(mixed $roleId): void
    {
        if ($roleId && $this->userEditing) {
            $updateRole = $roleId && $this->userEditing->isAdmin() && $this->userEditing->can(AbilityEnum::MANAGE_ROLES->value);
            if ($updateRole) {
                $role = RolesRepository::getByMixedParamFromCompany($roleId);

                $assign = new AssignRoleAction(
                    $this->user,
                    $role
                );
                $assign->execute();
            }
        }
    }
}
