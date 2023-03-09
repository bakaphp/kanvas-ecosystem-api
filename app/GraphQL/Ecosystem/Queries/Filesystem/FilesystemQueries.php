<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Filesystem;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Repositories\CustomFieldsRepository;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FilesystemQueries
{
    /**
     * Get all file from a entity tied to the graph
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function getFileByGraphType(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $systemModule = SystemModulesRepository::getByModelName($root::class);

        /**
         * @var Builder
         */
        return Filesystem::select(
            'filesystem_entities.uuid',
            'filesystem_entities.field_name',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'size'
        )
                    ->join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
                    ->where('filesystem_entities.entity_id', '=', $root->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());
    }

    /**
     * Get all file from a specific system module entity
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function getFilesFromSystemModuleEntity(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $args['entity']['name'] = 'filesystem';
        $args['entity']['data'] = [];

        $customFieldInput = CustomFieldInput::viaRequest($args['entity']);

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput, auth()->user());
        $systemModule = SystemModules::where('uuid', $customFieldInput->systemModuleUuid)
                                ->fromApp()
                                ->notDeleted()
                                ->firstOrFail();

        /**
         * @var Builder
         */
        return Filesystem::select(
            'filesystem_entities.uuid',
            'filesystem_entities.field_name',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'size'
        )
                    ->join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
                    ->where('filesystem_entities.entity_id', '=', $entity->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());
    }
}
