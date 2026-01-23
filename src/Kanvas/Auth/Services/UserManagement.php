<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Support\Arr;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\AbilityEnum;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\Models\UserAddress;
use Kanvas\Users\Models\Users;

use Sentry\State\Scope;

use function Sentry\captureException;
use function Sentry\withScope;

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

            /*           if (! isset($data['lastname'])) {
                          $data['lastname'] = ''; //Save it empty to avoid having a fullName with unwanted lastnam
                      }
 */
            //@todo when we update the login to use userAssociatedApps we need to remove this

            //Save it empty to avoid having a fullName with unwanted lastname, ex: "Max Max". We are having issues with some frontends sending only the firstname as a fullname.
            if (! isset($data['lastname']) && $this->app->get('dont_force_lastname_default')) {
                $data['lastname'] = '';
            }
            $this->user->update($data);
            $userAppProfile->update($data);

            if ($customFields) {
                $customFields = $this->filterPublicCustomFields($customFields);
                if (! empty($customFields)) {
                    $this->user->setAll(
                        $customFields,
                        true
                    );
                }
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

            //update roles if
            $this->updateRole($roleIds);
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        return $this->user;
    }

    /**
     * Filter custom fields to exclude blocked ones.
     * Supports both formats:
     * - Array format: [['name' => 'field', 'data' => 'value']]
     * - Key-value format: ['field' => 'value']
     */
    protected function filterPublicCustomFields(array $customFields): array
    {
        $blockedCustomFields = $this->app->get('blocked_user_custom_fields');

        if (! $blockedCustomFields || ! is_array($blockedCustomFields)) {
            return $customFields;
        }

        $filteredFields = [];
        $attemptedBlockedFields = [];

        // Detect format: check if first element has 'name' key (array format)
        $isArrayFormat = isset($customFields[0]) && is_array($customFields[0]) && isset($customFields[0]['name']);

        if ($isArrayFormat) {
            // Array format: [['name' => 'field', 'data' => 'value']]
            foreach ($customFields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldName = $field['name'] ?? null;
                if (! $fieldName || ! is_string($fieldName)) {
                    continue;
                }

                if (in_array($fieldName, $blockedCustomFields, true)) {
                    $attemptedBlockedFields[] = $fieldName;
                } else {
                    $filteredFields[] = $field;
                }
            }
        } else {
            // Key-value format: ['field' => 'value']
            foreach ($customFields as $fieldName => $fieldValue) {
                if (! is_string($fieldName)) {
                    continue;
                }

                if (in_array($fieldName, $blockedCustomFields, true)) {
                    $attemptedBlockedFields[] = $fieldName;
                } else {
                    $filteredFields[$fieldName] = $fieldValue;
                }
            }
        }

        if (! empty($attemptedBlockedFields)) {
            withScope(function (Scope $scope) use ($attemptedBlockedFields, $blockedCustomFields): void {
                $scope->setContext('custom_fields_violation', [
                    'user_id' => $this->user->getId(),
                    'app_id' => $this->app->getId(),
                    'blocked_fields' => $attemptedBlockedFields,
                    'configured_blocked_fields' => $blockedCustomFields,
                ]);

                captureException(new Exception(
                    'User attempted to set blocked custom fields: ' . implode(', ', $attemptedBlockedFields)
                ));
            });
        }

        return $filteredFields;
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
