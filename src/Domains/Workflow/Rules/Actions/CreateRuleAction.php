<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;

class CreateRuleAction
{
    public function __construct(
        protected readonly RuleData $data,
    ) {
    }

    public function execute(): Rule
    {
        return DB::connection('workflow')->transaction(function () {
            // Create the main Rule
            $rule = new Rule();
            $rule->apps_id = $this->data->app->getId();
            $rule->companies_id = $this->data->company->getId();
            $rule->systems_modules_id = $this->data->systemModule->getId();
            $rule->rules_types_id = $this->data->ruleType->getId();
            $rule->name = $this->data->name;
            $rule->description = $this->data->description;
            $rule->pattern = $this->data->pattern;
            $rule->params = $this->data->params;
            $rule->is_async = $this->data->is_async;
            $rule->saveOrFail();

            // Create RuleConditions
            foreach ($this->data->conditions as $conditionData) {
                $condition = new RuleCondition();
                $condition->rules_id = $rule->getId();
                $condition->attribute_name = $conditionData['attribute_name'];
                $condition->operator = $conditionData['operator'];
                $condition->value = $conditionData['value'];
                $condition->is_custom_attributes = $conditionData['is_custom_attributes'] ?? false;
                $condition->saveOrFail();
            }

            // Create RuleActions
            foreach ($this->data->actions as $actionData) {
                $ruleAction = new RuleAction();
                $ruleAction->rules_id = $rule->getId();
                $ruleAction->rules_workflow_actions_id = $actionData['rules_workflow_actions_id'];
                $ruleAction->weight = $actionData['weight'] ?? 0.00;
                $ruleAction->saveOrFail();
            }

            // Return rule with relationships loaded
            return $rule->fresh(['getRulesConditions', 'workflowActivities']);
        });
    }
}
