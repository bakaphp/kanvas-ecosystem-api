<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class CreateRuleAction
{
    public function __construct(
        protected readonly RuleData $data,
    ) {
    }

    public function execute(): Rule
    {
        return DB::connection('workflow')->transaction(function (): Rule {
            $rule = Rule::firstOrCreate([
                'name' => $this->data->name,
                'rules_types_id' => $this->data->ruleType->getId(),
                'systems_modules_id' => $this->data->systemModule->getId(),
                'apps_id' => $this->data->app->getId(),
                'companies_id' => $this->data->company->getId(),
            ], [
                'description' => $this->data->description,
                'params' => $this->data->params ?? [],
                'pattern' => $this->data->pattern,
                'is_async' => $this->data->is_async,
                'is_deleted' => 0,
            ]);

            if ($this->data->conditions !== null) {
                /** @var RuleConditionData $condition */
                foreach ($this->data->conditions as $condition) {
                    $rule->getRulesConditions()->firstOrCreate([
                        'attribute_name' => $condition->attribute_name,
                        'operator' => $condition->operator->value,
                        'value' => $condition->value,
                    ], [
                        'is_deleted' => 0,
                    ]);
                }
            }

            if ($this->data->actions !== null) {
                $weight = 0;
                /** @var RuleActionData $actionData */
                foreach ($this->data->actions as $actionData) {
                    $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate([
                        'system_modules_id' => $this->data->systemModule->getId(),
                        'actions_id' => $actionData->action->getId(),
                    ], [
                        'is_deleted' => 0,
                    ]);

                    RuleAction::firstOrCreate([
                        'rules_id' => $rule->getId(),
                        'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
                    ], [
                        'weight' => $actionData->weight ?? $weight,
                        'is_deleted' => 0,
                    ]);

                    $weight++;
                }
            }

            return $rule->refresh();
        });
    }
}
