<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Filesystem;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FileRelationship
{
    /**
     * all.
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function entityPagination(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $systemModule = SystemModulesRepository::getByModelName($root::class);

        /**
         * @var Builder
         */
        return Filesystem::join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
                    ->where('filesystem_entities.entity_id', '=', $root->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());
    }
}
