<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Models\AppsCustomFields;
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
}
