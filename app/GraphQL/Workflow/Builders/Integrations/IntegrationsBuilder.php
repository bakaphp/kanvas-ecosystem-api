<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Builders\Integrations;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class IntegrationsBuilder
{
    public function integrationEntityHistoryByEntity(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $systemModuleUuid = $args['system_module_uuid'];
        $systemModule = SystemModulesRepository::getByUuidOrModelName($systemModuleUuid);

        if (! class_exists($entity = $systemModule->model_name)) {
            throw new InternalServerErrorException('System Module not found.');
        }

        $entity = $entity::getById($args['entity_id']);

        return EntityIntegrationHistory::where('entity_namespace', get_class($entity))
            ->where('entity_id', $entity->getId());
    }
}
