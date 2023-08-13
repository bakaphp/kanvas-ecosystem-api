<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Social\Reactions\Models\UserReaction as UserReactionModel;

class UserReactionRepository
{
    public static function getUserReactionGroupBy(?string $entityNamespace = null, ?string $entityId = null): Collection
    {
        $builder = UserReactionModel::join('reactions', 'users_reactions.reactions_id', '=', 'reactions.id')
                            ->select('reactions_id', 'reactions.name', 'reactions.icon', DB::raw('count(*) as total'));

        if ($entityNamespace) {
            $builder->where('entity_namespace', $entityNamespace);
        }
        if ($entityId) {
            $builder->where('entity_id', $entityId);
        }

        return $builder->groupBy('reactions_id', 'reactions.name', 'reactions.icon')->get();
    }
}
