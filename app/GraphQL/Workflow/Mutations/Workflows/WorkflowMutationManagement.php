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
use Kanvas\Connectors\Shopify\Workflows\Activities\PushOrderActivity;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Souk\Orders\Models\Order;
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
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $isSync = (bool) ($request['params']['sync'] ?? false);
        $canRunSync = $isSync && $app->get('can-run-sync-workflow', false);
        $params['user'] = $user;

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
                $entity = match ($entityClass) {
                    Lead::class => new Lead(),
                    People::class => new People(),
                    Order::class => new Order(),
                    default => throw new ModelNotFoundException('Record ' . class_basename($entityClass) . " {$entityId} not found"),
                };
                $entity->fill([
                    'id' => 0,
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                ]);
            }
        }

        /**
         * @todo this is a stupid hack, but we will handle this for now until we figure out a better way
         */
        $caRunPullAndPush = in_array(SystemModules::getSlugBySystemModuleNameSpace($entityClass), ['lead', 'people', 'order']) && $canRunSync && in_array($workflowAction, [WorkflowEnum::PULL->value, WorkflowEnum::PUSH->value]);
        if ($caRunPullAndPush) {
            $pullActivity = match (SystemModules::getSlugBySystemModuleNameSpace($entityClass)) {
                'lead' => PullLeadActivity::class,
                'people' => PullPeopleActivity::class,
                'order' => PushOrderActivity::class,
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

            return $activity->execute($entity, $app, $params);
        }
        $results = $entity->fireWorkflow($workflowAction, true, $params);

        //if its sync we return the results
        if ($results instanceof SyncWorkflowStub) {
            $output = $results->output();

            return count($output) > 1 ? $output : current($output);
        }

        return ['success' => true];
    }
}
