<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Workflows;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Connectors\SalesAssist\Activities\PullPeopleActivity;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
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
        $company = auth()->user()->getCurrentCompany();
        $isSync = (bool) ($request['sync'] ?? false);
        $canRunSync = $isSync && $app->get('can-run-sync-workflow', false);

        //if we get a slug
        if (! Str::contains($entityClass, '\\')) {
            $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($entityClass);
        }

        if (! class_exists($entityClass)) {
            throw new Exception('Entity ' . $entityClass . ' not found');
        }

        //validate action
        try {
            WorkflowEnum::fromString($workflowAction);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
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
            if (! $canRunSync) {
                throw new ExceptionsModelNotFoundException('Record ' . class_basename($entityClass) . " {$entityId} not found");
            } else {
                $entity = $entityClass === Lead::class ? new Lead() : new People();
                $entity->fill([
                    'id' => 0,
                    'apps_id' => $app->getId(),
                    'companies_id' => $company,
                ]);
            }
        }

        /**
         * @todo this is a stupid hack, but we will handle this for now until we figure out a better way
         */
        if (in_array(SystemModules::getSlugBySystemModuleNameSpace($entityClass), ['lead', 'people'])) {
            $pullActivity = match (SystemModules::getSlugBySystemModuleNameSpace($entityClass)) {
                'lead' => PullLeadActivity::class,
                'people' => PullPeopleActivity::class,
                default => null,
            };

            if ($pullActivity === null) {
                throw new Exception('Activity not found');
            }

            $activity = new $pullActivity(
                index: 0,
                now: now()->toDateTimeString(),
                storedWorkflow: new StoredWorkflow(),
                arguments: []
            );

            return $activity->execute($entity, $app, []);
        }
        $results = $entity->fireWorkflow($workflowAction, true, $params);

        //if its sync we return the results
        if ($results instanceof SyncWorkflowStub) {
            return $results->output();
        }

        return ['success' => true];
    }
}
