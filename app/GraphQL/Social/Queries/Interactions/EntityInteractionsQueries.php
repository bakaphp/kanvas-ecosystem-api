<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Interactions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EntityInteractionsQueries
{
    public function getAll(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        return EntityInteractions::select(
            'entity_id',
            'entity_namespace',
            'interacted_entity_id',
            'interacted_entity_namespace',
        )
        ->where('entity_id', $args['entity_id'])
        ->where('entity_namespace', $args['entity_namespace'])
        ->groupBy(
            'entity_interactions.entity_id',
            'entity_interactions.entity_namespace',
            'entity_interactions.interacted_entity_id',
            'entity_interactions.interacted_entity_namespace',

        );
    }
}
