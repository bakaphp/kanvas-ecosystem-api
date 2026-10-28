<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * A sync `pull` rule on leads for the current app, wired to one activity. `pull` because nothing
 * else listens on it for leads, so a test measures its own rule and not every created rule in the
 * database.
 */
trait BuildsSyncPullRules
{
    protected function createSyncPullRule(
        Apps $app,
        string $activityClass,
        string $actionName,
        array $params = []
    ): Rule {
        return $this->createSyncRule(
            $app,
            Lead::class,
            WorkflowEnum::PULL,
            $activityClass,
            $actionName,
            $params
        );
    }

    /**
     * A sync rule on any model and event, wired to one activity.
     */
    protected function createSyncRule(
        Apps $app,
        string $modelClass,
        WorkflowEnum $event,
        string $activityClass,
        string $actionName,
        array $params = []
    ): Rule {
        $action = Action::factory()->create([
            'name' => $actionName,
            'model_name' => $activityClass,
        ]);
        $systemModuleId = SystemModulesRepository::getByModelName($modelClass, $app)->getId();

        $rule = Rule::factory()->withAsync(false)->create([
            'name' => $event->value . ' ' . class_basename($modelClass) . ' through ' . class_basename($activityClass) . ' ' . uniqid(),
            'rules_types_id' => RuleType::getByName($event->value)->getId(),
            'systems_modules_id' => $systemModuleId,
            'params' => $params,
        ]);

        $workflowAction = RuleWorkflowAction::factory()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $systemModuleId,
        ]);

        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $workflowAction->getId(),
            'weight' => 0,
        ]);

        return $rule;
    }

    protected function createLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
