<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

use function Sentry\captureException;

use Sentry\State\Scope;

use function Sentry\withScope;

class CustomFieldMutation
{
    /**
     * Set custom field
     */
    public function create(mixed $rootValue, array $request): bool
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = SystemModulesRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'set')) {
            if ($entity instanceof Users || $entity instanceof UserInterface) {
                $customFields = $this->filterPublicCustomFields(
                    app: app(Apps::class),
                    user: $entity,
                    customFields: [$customFieldInput->name => $customFieldInput->data]
                );

                if (empty($customFields)) {
                    return false;
                }
            }

            $customField = $entity->set(
                $customFieldInput->name,
                $customFieldInput->data
            );

            return $customField instanceof AppsCustomFields || $customField === true;
        }

        return false;
    }

    /**
     * @deprecated use query
     */
    public function get(mixed $rootValue, array $request): mixed
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = SystemModulesRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'get')) {
            return $entity->get(
                $customFieldInput->name
            );
        }

        return null;
    }

    /**
     * @deprecated use query
     */
    public function getAll(mixed $rootValue, array $request): array
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = SystemModulesRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'getAll')) {
            return $entity->getAll();
        }

        return [];
    }

    /**
     * Delete custom field
     */
    public function delete(mixed $rootValue, array $request): bool
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = SystemModulesRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'del')) {
            return (bool) $entity->del(
                $customFieldInput->name
            );
        }

        return false;
    }

    protected function filterPublicCustomFields(AppInterface $app, UserInterface $user, array $customFields): array
    {
        $blockedCustomFields = $app->get('blocked_user_custom_fields');

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
            withScope(function (Scope $scope) use ($attemptedBlockedFields, $blockedCustomFields, $user, $app): void {
                $scope->setContext('custom_fields_violation', [
                    'user_id' => $user->getId(),
                    'app_id' => $app->getId(),
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
}
