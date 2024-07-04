<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\UsersInteractions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Interactions\Models\UsersInteractions;

class GetUserInteraction
{
    public function __invoke($rootValue, array $args): array
    {
        $userInteraction = UsersInteractions::where('entity_id', $args['entity_id'])
            ->join('interactions', 'users_interactions.interactions_id', '=', 'interactions.id')
            ->where('entity_namespace', $args['entity_namespace'])
            ->select('interactions.name', 'interactions.title', DB::raw('count(*) as total'))
            ->groupBy('interactions.name', 'interactions.title')
            ->get();

        return [
                    'entity_id' => $args['entity_id'],
                    'entity_namespace' => $args['entity_namespace'],
                    'interactions' => $userInteraction,
            ];
    }
}
