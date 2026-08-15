<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Filesystem;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\SystemModules\DataTransferObject\SystemModuleEntityInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FilesystemQuery
{
    /**
     * Get all file from a entity tied to the graph
     */
    public function getFileByGraphType(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $systemModule = SystemModulesRepository::getByModelName($root::class);
        $app = $systemModule->app;

        /**
         * @todo use directly from the entity via fileQueryBuilder
         */
        $files = Filesystem::select(
            'filesystem_entities.uuid',
            'filesystem.uuid as filesystem_uuid',
            'filesystem_entities.field_name',
            'filesystem_entities.weight',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'filesystem.file_type as type',
            'filesystem_entities.id',
            'filesystem.created_at'
        )
            ->join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
            ->where('filesystem_entities.entity_id', '=', $root->getKey())
            ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
            ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
            ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());

        $files->when(isset($root->companies_id) && ! $app->get(AppSettingsEnums::GLOBAL_APP_IMAGES->getValue()), function ($query) use ($root) {
            $query->where('filesystem_entities.companies_id', $root->companies_id);
        });

        return $files;
    }

    /**
     * Same result as getFileByGraphType, but returns a ready-built LengthAwarePaginator
     * from a single query instead of letting @paginate(builder:) run a separate count(*).
     *
     * An entity carries a handful of files, so we fetch them all in one query and paginate
     * the collection in memory. This keeps the FilesystemPaginator shape intact (data +
     * paginatorInfo) while dropping the per-parent count(*) — the N+1 offender when a bulk
     * products list (e.g. a sitemap crawl) resolves files for every product and variant on
     * a cold @cacheRedis cache. Wire it as @paginate(resolver: ...) on high-fan-out types.
     */
    public function getPaginatedFileByGraphType(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): LengthAwarePaginator {
        $perPage = max(1, (int) ($args['first'] ?? 25));
        $page = max(1, (int) ($args['page'] ?? 1));

        // A high-fan-out list (e.g. channelVariants) can eager-load `filesForGraphType` so the
        // per-entity files query is batched into one `entity_id IN (...)`. Read the loaded
        // relation when present; otherwise fall back to the single-entity query.
        $files = is_object($root) && method_exists($root, 'relationLoaded') && $root->relationLoaded('filesForGraphType')
            ? $root->getRelation('filesForGraphType')
            : $this->getFileByGraphType(
                $root,
                $args,
                $context,
                $resolveInfo
            )->get();

        return new LengthAwarePaginator(
            $files->forPage($page, $perPage)->values(),
            $files->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * Get all file from a specific system module entity
     */
    public function getFilesFromSystemModuleEntity(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $args['entity']['name'] = 'filesystem';
        $args['entity']['data'] = [];

        $entityInput = SystemModuleEntityInput::viaRequest($args['entity']);

        $entity = SystemModulesRepository::getEntityFromInput($entityInput, auth()->user());

        $systemModule = SystemModulesRepository::getByUuidOrModelName($entityInput->systemModuleUuid);
        $app = $systemModule->app;

        /**
         * @var Builder
         */
        $files = Filesystem::select(
            'filesystem_entities.uuid',
            'filesystem_entities.field_name',
            'filesystem_entities.weight',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'size',
            'filesystem.id'
        )
            ->join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
            ->where('filesystem_entities.entity_id', '=', $entity->getKey())
            ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
            ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
            ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());

        //@todo allow to share media between company only of it the apps specifies it
        $files->when(isset($root->companies_id) && ! $app->get(AppSettingsEnums::GLOBAL_APP_IMAGES->getValue()), function ($query) use ($entity) {
            $query->where('filesystem_entities.companies_id', $entity->companies_id);
        });

        return $files;
    }
}
