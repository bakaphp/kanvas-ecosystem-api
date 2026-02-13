<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Rule;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\Actions\UpdateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class RuleMutation
{
    public function create(mixed $rootValue, array $request): Rule
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $firstActionId = $input['actions'][0]['rules_workflow_actions_id'];
        $firstAction = RuleWorkflowAction::findOrFail($firstActionId);
        $systemModule = SystemModules::getById($firstAction->system_modules_id);

        foreach ($input['actions'] as $actionData) {
            $action = RuleWorkflowAction::findOrFail($actionData['rules_workflow_actions_id']);
            if ($action->system_modules_id !== $systemModule->getId()) {
                throw new ValidationException(
                    "All actions must belong to the same system module. " .
                    "Expected module {$systemModule->getId()}, but action {$action->getId()} belongs to module {$action->system_modules_id}"
                );
            }
        }

        $ruleType = RuleType::findOrFail((int) $input['rules_types_id']);

        return new CreateRuleAction(
            new RuleData(
                app: $app,
                company: $company,
                user: $user,
                systemModule: $systemModule,
                ruleType: $ruleType,
                name: $input['name'],
                pattern: $input['pattern'],
                description: $input['description'] ?? null,
                params: $input['params'] ?? [],
                is_async: $input['is_async'] ?? true,
                conditions: $input['conditions'] ?? [],
                actions: $input['actions'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Rule
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $rule = Rule::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if (isset($input['actions']) && ! empty($input['actions'])) {
            $firstActionId = $input['actions'][0]['rules_workflow_actions_id'];
            $firstAction = RuleWorkflowAction::findOrFail($firstActionId);
            $systemModule = SystemModules::getById($firstAction->system_modules_id);

            foreach ($input['actions'] as $actionData) {
                $action = RuleWorkflowAction::findOrFail($actionData['rules_workflow_actions_id']);
                if ($action->system_modules_id !== $systemModule->getId()) {
                    throw new ValidationException(
                        "All actions must belong to the same system module. " .
                        "Expected module {$systemModule->getId()}, but action {$action->getId()} belongs to module {$action->system_modules_id}"
                    );
                }
            }
        } else {
            $systemModule = $rule->systemModule;
        }

        $ruleType = isset($input['rules_types_id'])
            ? RuleType::findOrFail((int) $input['rules_types_id'])
            : $rule->type;

        return new UpdateRuleAction(
            $rule,
            new RuleData(
                app: $app,
                company: $company,
                user: $user,
                systemModule: $systemModule,
                ruleType: $ruleType,
                name: $input['name'] ?? $rule->name,
                pattern: $input['pattern'] ?? $rule->pattern,
                description: $input['description'] ?? $rule->description,
                params: $input['params'] ?? $rule->params,
                is_async: $input['is_async'] ?? $rule->is_async,
                conditions: $input['conditions'] ?? [],
                actions: $input['actions'] ?? [],
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $rule = Rule::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $rule->softDelete();
    }
}
