<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Filesystem;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
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
            'filesystem_entities.field_name',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'filesystem.file_type as type',
            'filesystem_entities.id',
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
