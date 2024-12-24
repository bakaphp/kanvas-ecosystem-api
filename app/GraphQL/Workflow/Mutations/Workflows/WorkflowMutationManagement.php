<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Workflows;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;

class WorkflowMutationManagement
{
    public function runWorkflowFromEntity(mixed $rootValue, array $request): array
    {
        /**
         * @todo missing test for this mutation
         */
        $request = $request['input'];
        $entityId = $request['entity_id'];
        $entityClass = $request['entity_namespace'];
        $workflowAction = $request['action'];
        $params = $request['params'] ?? [];
        $app = app(Apps::class);

        //if we get a slug
        if (Str::contains($entityClass, '\\')) {
            $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($entityClass);
        }

        if (! class_exists($entityClass)) {
            return false;
        }

        $entity = $entityClass::getById($entityId, $app);

        //validate action
        WorkflowEnum::fromString($workflowAction);

        $entity->fireWorkflow(
            $workflowAction,
            true,
            array_merge(['app' => $app], $params)
        );

        return ['success' => true];
    }
}
