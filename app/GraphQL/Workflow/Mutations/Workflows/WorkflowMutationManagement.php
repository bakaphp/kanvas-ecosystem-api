<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Workflows;

use Baka\Contracts\CompanyInterface;
use Baka\Support\IPInfo;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
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
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use ReflectionClass;
use Workflow\WorkflowStub;

class WorkflowMutationManagement
{
    public function runWorkflowFromEntity(mixed $rootValue, array $request): mixed
    {
        $request = $request['input'];
        $entityId = $request['entity_id'];
        $entityClass = $request['entity_namespace'];
        $workflowAction = $request['action'];
        $params = array_merge(['app' => app(Apps::class)], $request['params'] ?? [], ['ip' => IPInfo::getClientIp()]);
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

        $activityClass = $request['activity_class'] ?? null;
        $includeRun = (bool) ($request['include_run'] ?? false);

        if ($activityClass !== null) {
            $this->assertRunnableActivity($activityClass);

            $entity = empty($entityId)
                ? $this->stubEntity($entityClass, $app, $company)
                : $this->findEntity($entityClass, $entityId, $app);

            return $this->runActivity($activityClass, $entity, $app, $params, $includeRun);
        }

        try {
            /**
             * @todo this look very similar to the system module repository method, so you many need
             * to refactor this to use the repository method
             */
            $entity = $this->findEntity($entityClass, $entityId, $app);
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            if (! $canRunSync) {
                throw new ExceptionsModelNotFoundException('Record ' . class_basename($entityClass) . " {$entityId} not found");
            }

            if (! in_array($entityClass, [Lead::class, People::class, Order::class], true)) {
                throw new ModelNotFoundException('Record ' . class_basename($entityClass) . " {$entityId} not found");
            }

            $entity = $this->stubEntity($entityClass, $app, $company);
        }

        $entitySlug = SystemModules::getSlugBySystemModuleNameSpace($entityClass);
        $canRunPullAndPush = in_array($entitySlug, ['lead', 'people', 'order']) && $canRunSync && in_array($workflowAction, [WorkflowEnum::PULL->value, WorkflowEnum::PUSH->value]);

        /**
         * Rules are the routing table: an app or company decides which connector answers a pull/push
         * by pointing a rule at its activity. The SalesAssist match below only answers tenants that
         * have no rule yet; it goes away once those tenants carry rules of their own.
         */
        $processor = new ProcessWorkflowEventAction($app, $entity);

        if (! $processor->hasRules($workflowAction, $params) && $canRunPullAndPush) {
            $pullActivity = match ($entitySlug) {
                'lead' => PullLeadActivity::class,
                'people' => PullPeopleActivity::class,
                'order' => PushOrderActivity::class,
                default => null,
            };

            if ($pullActivity === null) {
                throw new Exception('Activity not found');
            }

            /*    Log::debug('runWorkflowFromEntity: no rule for this pull/push, falling back to the SalesAssist activity', [
                   'apps_id' => $app->getId(),
                   'companies_id' => $company->getId(),
                   'entity' => $entityClass,
                   'action' => $workflowAction,
                   'activity' => $pullActivity,
               ]); */

            return $this->runActivity($pullActivity, $entity, $app, $params, $includeRun);
        }

        $results = $processor->execute($workflowAction, $params);

        if ($results instanceof WorkflowStub) {
            $output = $results->output() ?? [];
            $output = count($output) > 1 ? $output : current($output);

            if (! $includeRun) {
                return $output;
            }

            return [
                'success' => ! $results->failed(),
                'workflow_id' => $results->id(),
                'history_id' => EntityIntegrationHistory::query()
                    ->where('workflow_id', $results->id())
                    ->orderByDesc('id')
                    ->value('id'),
                'output' => $output,
            ];
        }

        return ['success' => true, 'workflow_ids' => $processor->startedWorkflowIds()];
    }

    /**
     * Any class name reaches here from the client; only a catalogued activity may be run by name.
     */
    private function assertRunnableActivity(string $activityClass): void
    {
        if (! class_exists($activityClass)) {
            throw new Exception('activity_class ' . $activityClass . ' not found');
        }

        if (! is_subclass_of($activityClass, KanvasActivity::class)
            || new ReflectionClass($activityClass)->getAttributes(WorkflowAction::class) === []
        ) {
            throw new Exception('activity_class ' . $activityClass . ' is not a workflow activity');
        }
    }

    private function findEntity(string $entityClass, mixed $entityId, Apps $app): Model
    {
        return Str::isUuid($entityId)
            ? $entityClass::getByUuid($entityId, $app)
            : $entityClass::getById($entityId, $app);
    }

    /**
     * A pull by external id has no Kanvas record yet, so the activity gets an id-0 entity of the
     * right class that still carries the tenant.
     */
    private function stubEntity(string $entityClass, Apps $app, CompanyInterface $company): Model
    {
        $entity = new $entityClass();
        $entity->fill([
            'id' => 0,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);
        $entity->setRelation('company', $company);

        return $entity;
    }

    private function runActivity(
        string $activityClass,
        Model $entity,
        Apps $app,
        array $params,
        bool $includeRun = false
    ): mixed {
        $activity = new $activityClass(
            index: 0,
            now: now()->toDateTimeString(),
            storedWorkflow: new StoredWorkflow(),
            arguments: []
        );

        $output = $activity->executeRecorded($entity, $app, $params);

        if (! $includeRun) {
            return $output;
        }

        return [
            'success' => $activity->workflowStatus() !== StatusEnum::FAILED,
            'workflow_id' => null,
            'history_id' => $activity->lastIntegrationHistory()?->getId(),
            'output' => $output,
        ];
    }
}
