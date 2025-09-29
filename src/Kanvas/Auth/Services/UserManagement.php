<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Arr;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\AbilityEnum;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\Models\UserAddress;
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
        $app = app(Apps::class);
        try {
            $customFields = null;
            $files = null;
            $customFields = Arr::pull($data, 'custom_fields', []);
            $files = Arr::pull($data, 'files', []);
            $roleIds = Arr::pull($data, 'role_ids', []);

            $userAppProfile = $this->user->getAppProfile($this->app);

            /*           if (! isset($data['lastname'])) {
                          $data['lastname'] = ''; //Save it empty to avoid having a fullName with unwanted lastnam
                      }
 */
            //@todo when we update the login to use userAssociatedApps we need to remove this
            $this->user->update($data);
            $userAppProfile->update($data);

            if ($customFields) {
                $this->user->setAll($customFields, true);
            }

            if (isset($data['addresses'])) {
                foreach ($data['addresses'] as $addressData) {
                    $existingAddress = UserAddress::where('users_id', $this->user->getId())
                        ->where('address', $addressData['address'])
                        ->where('city', $addressData['city'])
                        ->where('state', $addressData['state'])
                        ->where('zip', $addressData['zip'])
                        ->where('apps_id', $this->app->getId())
                        ->first();

                    if ($existingAddress) {
                        if (! isset($addressData['id']) || $existingAddress->id != $addressData['id']) {
                            continue;
                        }
                    }

                    $attributes = [
                        'address' => $addressData['address'],
                        'city' => $addressData['city'],
                        'state' => $addressData['state'],
                        'zip' => $addressData['zip'],
                        'is_default' => $addressData['is_default'] ?? false,
                        'apps_id' => $this->app->getId(),
                        'country_id' => $addressData['country_id'],
                        'users_id' => $this->user->getId(),
                    ];

                    if (isset($addressData['id'])) {
                        UserAddress::where('id', $addressData['id'])
                            ->where('users_id', $this->user->getId())
                            ->where('apps_id', $this->app->getId())
                            ->update($attributes);
                    } else {
                        UserAddress::create($attributes);
                    }
                }
            }

            if ($files) {
                $this->user->addMultipleFilesFromUrl($files);
            }


            if (! isset($data['lastname']) && $app->get('dont_force_lastname_default')) {
                $data['lastname'] = ''; //Save it empty to avoid having a fullName with unwanted lastname
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
