<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Reactions\Models\UserReaction as UserReactionModel;

class UserReactionRepository
{
    public static function getUserReactionGroupBy(?string $entityNamespace = null, ?string $entityId = null): Collection
    {
        $builder = UserReactionModel::join('reactions', 'users_reactions.reactions_id', '=', 'reactions.id')
                            ->when($entityNamespace, function ($query, $entityNamespace) use ($entityId) {
                                return $query->where('entity_namespace', $entityNamespace)->where('entity_id', $entityId);
                            })
                            ->select('reactions_id', 'reactions.name', 'reactions.icon', DB::raw('count(*) as total'));

        return $builder->groupBy('reactions_id', 'reactions.name', 'reactions.icon')->get();
    }
}
