<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Reactions;

use Kanvas\Social\Reactions\Repositories\UserReactionRepository;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Illuminate\Database\Eloquent\Collection;

class UserReactionQueries
{
    public function getUserReactionGroupByReaction(mixed $root, array $request): Collection
    {
        if (key_exists('entity_namespace', $request)) {
            $systemModule = SystemModulesRepository::getByUuidOrModelName($request['entity_namespace']);
        }

        return UserReactionRepository::getUserReactionGroupBy($systemModule->model_name ?? null, $req['entity_id'] ?? null);
    }
}
