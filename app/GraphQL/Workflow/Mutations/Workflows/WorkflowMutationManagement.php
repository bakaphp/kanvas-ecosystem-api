<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Enums\WorkflowEnum;

class WorkflowMutationManagement
{
    public function runWorkflowFromEntity(mixed $rootValue, array $request): bool
    {
        /**
         * @todo missing test for this mutation
         */
        $request = $request['input'];
        $entityId = $request['entity_id'];
        $entityClass = $request['entity_namespace'];
        $workflowAction = $request['action'];
        $app = app(Apps::class);

        if (! class_exists($entityClass)) {
            return false;
        }

        $entity = $entityClass::getById($entityId, $app);

        //validate action
        WorkflowEnum::fromString($workflowAction);

        $entity->fireWorkflow(
            $workflowAction,
            true,
            ['app' => $app]
        );

        return true;
    }
}
