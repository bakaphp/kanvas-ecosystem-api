<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\CustomFields;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CustomFieldQueries
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
        $files = AppsCustomFields::where('entity_id', '=', $root->getKey())
            ->where('model_name', '=', $root::class)
            ->where('is_deleted', '=', StateEnums::NO->getValue());

        //@todo allow to share media between company only of it the apps specifies it
        $files->when(isset($root->companies_id), function ($query) use ($root) {
            $query->where('companies_id', $root->companies_id);
        });

        return $files;
    }
}
