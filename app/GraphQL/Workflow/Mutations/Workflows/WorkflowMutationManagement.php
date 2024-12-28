<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Workflows;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\SyncWorkflowStub;

class WorkflowMutationManagement
{
    public function runWorkflowFromEntity(mixed $rootValue, array $request): mixed
    {
        /**
         * @todo missing test for this mutation
         */
        $request = $request['input'];
        $entityId = $request['entity_id'];
        $entityClass = $request['entity_namespace'];
        $workflowAction = $request['action'];
        $params = array_merge(['app' => app(Apps::class)], $request['params'] ?? [], ['ip' => request()->ip()]);
        $app = app(Apps::class);

        //if we get a slug
        if (! Str::contains($entityClass, '\\')) {
            $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($entityClass);
        }

        if (! class_exists($entityClass)) {
            throw new Exception('Entity ' . $entityClass . ' not found');
        }

        try {
            /**
             * @todo this look very similar to the system module repository method, so you many need
             * to refactor this to use the repository method
             */
            $entity = Str::isUuid($entityId)
                ? $entityClass::getByUuid($entityId, $app)
                : $entityClass::getById($entityId, $app);
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Record ' . class_basename($entityClass) . " {$entityId} not found");
        }

        //validate action
        WorkflowEnum::fromString($workflowAction);

        $results = $entity->fireWorkflow($workflowAction, true, $params);

        //if its sync we return the results
        if ($results instanceof SyncWorkflowStub) {
            return $results->output();
        }

        return ['success' => true];
    }
}
