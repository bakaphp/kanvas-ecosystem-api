<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Builders\Integrations;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Regions\Models\Regions;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
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
        $systemModule = SystemModulesRepository::getByUuidOrModelName($systemModuleUuid, app(Apps::class));

        if (! class_exists($entity = $systemModule->model_name)) {
            throw new InternalServerErrorException('System Module not found.');
        }

        $entity = $entity::getById($args['entity_id']);

        return EntityIntegrationHistory::where('entity_namespace', get_class($entity))
            ->where('entity_id', $entity->getId());
    }

    public function getHasRegion(mixed $root, array $args): Builder
    {
        $regionTable = Regions::getFullTableName();

        $integrationsCompanyTable = IntegrationsCompany::getFullTableName();
        $entityIntegrationHistoryTable = EntityIntegrationHistory::getFullTableName();

        $root->select([
                'entity_integration_history.*',
                'entity_integration_history.id as id'
            ])
            ->join($integrationsCompanyTable, $entityIntegrationHistoryTable . '.integrations_company_id', '=', $integrationsCompanyTable . '.id')
            ->join($regionTable, $integrationsCompanyTable . '.region_id', '=', $regionTable . '.id')
            ->distinct();

        if (isset($args['HAS']['condition'])) {
            $column = $args['HAS']['condition']['column'] ?? null;
            $value = $args['HAS']['condition']['value'] ?? null;
            if ($column && $value) {
                $root->when(
                    $value,
                    fn ($query) =>
                    $query->where($regionTable . '.' . $column, $value)
                );
            }
        }

        return $root;
    }
}
