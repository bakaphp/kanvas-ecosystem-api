<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Repositories\CustomFieldsRepository;

class CustomFieldMutation
{
    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return
     */
    public function create($rootValue, array $request)
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput);

        if (method_exists($entity, 'set')) {
            return $entity->set(
                $customFieldInput->name,
                $customFieldInput->data
            );
        }

        return false;
    }

    public function get($rootValue, array $request)
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput);

        if (method_exists($entity, 'get')) {
            return $entity->get(
                $customFieldInput->name
            );
        }

        return false;
    }

    public function delete($rootValue, array $request)
    {
        $customFieldInput = CustomFieldInput::viaRequest($request['input']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput);

        if (method_exists($entity, 'get')) {
            return $entity->del(
                $customFieldInput->name
            );
        }

        return false;
    }
}
