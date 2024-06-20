<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Interactions;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EntityInteractionsBuilder
{
    public function getAll(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {

        return EntityInteractions::where('entity_id', '=', $root->uuid)
            ->where('entity_namespace', '=', $root::class)
            ->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
