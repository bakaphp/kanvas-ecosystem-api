<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\CustomFields;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CustomFieldQuery
{
    /**
     * Get all file from a entity tied to the graph
     */
    public function getAllByGraphType(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        /**
         * @var Builder
         */
        $customFields = AppsCustomFields::where('entity_id', '=', $root->getKey())
            ->where('model_name', '=', $root::class)
            ->where('is_deleted', '=', StateEnums::NO->getValue());

        //@todo allow to share media between company only of it the apps specifies it
        $customFields->when(isset($root->companies_id), function ($query) use ($root) {
            $query->where('companies_id', $root->companies_id);
        });

        return $customFields;
    }

    /**
    * Get custom field
    */
    public function get(mixed $rootValue, array $request): mixed
    {
        $customFieldInput = new CustomFieldInput(
            $request['name'],
            $request['system_module_uuid'],
            $request['entity_id']
        );

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
        $customFieldInput = new CustomFieldInput(
            $request['name'],
            $request['system_module_uuid'],
            $request['entity_id']
        );
        $entity = SystemModulesRepository::getEntityFromInput($customFieldInput, auth()->user());

        if (method_exists($entity, 'getAll')) {
            return $entity->getAll();
        }

        return [];
    }
}
