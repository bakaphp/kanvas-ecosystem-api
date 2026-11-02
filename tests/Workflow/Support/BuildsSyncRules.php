<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * One sync rule on one module for one event, wired to one activity. Sync so the activity runs inline
 * and the test reads its result in the same process. Scope it to the test company when the event is
 * one other rules in the database also listen on (`created`), so the test measures its own rule.
 */
trait BuildsSyncRules
{
    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $conditions attribute, operator, value; joined with `and`.
     *                                                                 None keeps the factory pattern `1`, which matches
     *                                                                 everything until the test adds its own condition.
     */
    protected function createSyncRule(
        Apps $app,
        string $event,
        string $modelClass,
        string $activityClass,
        ?CompanyInterface $company = null,
        array $conditions = [],
        array $params = [],
        ?string $actionName = null,
    ): Rule {
        $action = Action::factory()->create([
            'name' => $actionName ?? class_basename($activityClass),
            'model_name' => $activityClass,
        ]);
        $systemModuleId = SystemModulesRepository::getByModelName($modelClass, $app)->getId();
        $ruleType = RuleType::firstOrCreate(['name' => $event]);

        $rule = Rule::factory()->withAsync(false)->create([
            'name' => $event . ' through ' . class_basename($activityClass) . ' ' . uniqid(),
            'rules_types_id' => $ruleType->getId(),
            'systems_modules_id' => $systemModuleId,
            'apps_id' => $app->getId(),
            'companies_id' => $company?->getId() ?? AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'params' => $params,
            'pattern' => $conditions === [] ? '1' : implode(' and ', range(1, count($conditions))),
        ]);

        foreach ($conditions as [$attribute, $operator, $value]) {
            RuleCondition::factory()->create([
                'rules_id' => $rule->getId(),
                'attribute_name' => $attribute,
                'operator' => $operator,
                'value' => $value,
            ]);
        }

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
}
