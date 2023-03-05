<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\CustomFields\Repositories\CustomFieldsRepository;

class CustomFieldMutation
{
    /**
     * Set custom field
     *
     * @param mixed $rootValue
     * @param array $request
     * @return bool
     */
    public function create(mixed $rootValue, array $request): bool
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'set')) {
            $customField =  $entity->set(
                $customFieldInput->name,
                $customFieldInput->data
            );

            return $customField instanceof CustomFields || $customField === true;
        }

        return false;
    }

    /**
     * Get custom field
     *
     * @param mixed $rootValue
     * @param array $request
     * @return mixed
     */
    public function get(mixed $rootValue, array $request): mixed
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'get')) {
            return $entity->get(
                $customFieldInput->name
            );
        }

        return null;
    }

    /**
     * Delete custom field
     *
     * @param mixed $rootValue
     * @param array $request
     * @return bool
     */
    public function delete(mixed $rootValue, array $request): bool
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'del')) {
            return (bool) $entity->del(
                $customFieldInput->name
            );
        }

        return false;
    }
}
