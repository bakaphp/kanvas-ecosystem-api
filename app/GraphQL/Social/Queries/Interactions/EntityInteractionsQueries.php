<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Interactions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EntityInteractionsQueries
{
    public function getAll(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        return EntityInteractions::where('entity_id', $args['entity_id'])
                ->where('entity_namespace', $args['entity_namespace']);
    }
}
