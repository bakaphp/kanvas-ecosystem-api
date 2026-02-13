<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;

class UpdateRuleAction
{
    public function __construct(
        protected readonly Rule $rule,
        protected readonly RuleData $data,
    ) {
    }

    public function execute(): Rule
    {
        return DB::connection('workflow')->transaction(function () {
            // Update the main Rule
            $this->rule->systems_modules_id = $this->data->systemModule->getId();
            $this->rule->rules_types_id = $this->data->ruleType->getId();
            $this->rule->name = $this->data->name;
            $this->rule->description = $this->data->description;
            $this->rule->pattern = $this->data->pattern;
            $this->rule->params = $this->data->params;
            $this->rule->is_async = $this->data->is_async;
            $this->rule->saveOrFail();

            // Delete and recreate RuleConditions if provided
            if (! empty($this->data->conditions)) {
                $this->rule->getRulesConditions()->delete();

                foreach ($this->data->conditions as $conditionData) {
                    $condition = new RuleCondition();
                    $condition->rules_id = $this->rule->getId();
                    $condition->attribute_name = $conditionData['attribute_name'];
                    $condition->operator = $conditionData['operator'];
                    $condition->value = $conditionData['value'];
                    $condition->is_custom_attributes = $conditionData['is_custom_attributes'] ?? false;
                    $condition->saveOrFail();
                }
            }

            // Delete and recreate RuleActions if provided
            if (! empty($this->data->actions)) {
                $this->rule->workflowActivities()->delete();

                foreach ($this->data->actions as $actionData) {
                    $ruleAction = new RuleAction();
                    $ruleAction->rules_id = $this->rule->getId();
                    $ruleAction->rules_workflow_actions_id = $actionData['rules_workflow_actions_id'];
                    $ruleAction->weight = $actionData['weight'] ?? 0.00;
                    $ruleAction->saveOrFail();
                }
            }

            // Return rule with relationships loaded
            return $this->rule->fresh(['getRulesConditions', 'workflowActivities']);
        });
    }
}
