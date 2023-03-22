<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

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
            $customField = $entity->set(
                $customFieldInput->name,
                $customFieldInput->data
            );

            return $customField instanceof CustomFields || $customField === true;
        }

        return false;
    }

    /**
     * Get custom field
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
     * Get custom field
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
}
