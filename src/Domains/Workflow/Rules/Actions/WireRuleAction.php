<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * Idempotently wires "when {event} fires on {entity}, run {activity}" for an app (and optionally one
 * company). Returns null when the activity has no Action row yet: `kanvas:workflow-sync-actions`
 * creates those on deploy, and a setup command must say so rather than create a dangling rule.
 *
 * `$conditions` is a list of `['attribute_name' => string, 'operator' => RuleConditionOperatorEnum|string,
 * 'value' => string]`. The pattern defaults to `1`: with no conditions it is a literal true, with one it
 * is condition #1, so a caller with several passes `$pattern` (`1 and 2`). It is written when the rule
 * is created, never over a pattern someone edited since.
 */
final class WireRuleAction
{
    public const string MISSING_ACTION = 'Action row not found for %s. Run `php artisan kanvas:workflow-sync-actions` first.';

    public function __construct(
        private readonly Apps $app,
        private readonly int $companyId,
        private readonly string $entityClass,
        private readonly string $ruleTypeName,
        private readonly string $activityClass,
        private readonly string $name,
        private readonly string $description,
        private readonly array $conditions = [],
        private readonly array $params = [],
        private readonly bool $isAsync = false,
        private readonly ?string $pattern = null,
    ) {
    }

    public function execute(): ?Rule
    {
        $action = Action::where('model_name', $this->activityClass)->first();
        if (! $action) {
            return null;
        }

        $systemModule = SystemModulesRepository::getByModelName($this->entityClass, $this->app);
        $ruleType = RuleType::firstOrCreate(['name' => $this->ruleTypeName], ['is_deleted' => 0]);

        $rule = Rule::firstOrCreate(
            [
                'name' => $this->name,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->companyId,
            ],
            [
                'description' => $this->description,
                // pattern '1' with no conditions evaluates truthy — the activity self-guards on entity/type.
                'pattern' => $this->pattern ?? 1,
                'params' => $this->params,
                'is_async' => $this->isAsync,
                'is_deleted' => 0,
            ]
        );

        if ((int) $rule->is_deleted === 1) {
            $rule->forceFill(['is_deleted' => 0])->saveOrFail();
        }

        foreach ($this->conditions as $condition) {
            $operator = $condition['operator'] instanceof RuleConditionOperatorEnum
                ? $condition['operator']->value
                : (string) $condition['operator'];

            RuleCondition::firstOrCreate([
                'rules_id' => $rule->getId(),
                'attribute_name' => $condition['attribute_name'],
                'operator' => $operator,
                'value' => $condition['value'],
            ]);
        }

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $action->getId(),
            ],
            ['is_deleted' => 0]
        );

        if ((int) $ruleWorkflowAction->is_deleted === 1) {
            $ruleWorkflowAction->forceFill(['is_deleted' => 0])->saveOrFail();
        }

        $step = RuleAction::firstOrCreate(
            [
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ],
            ['weight' => 0, 'is_deleted' => 0]
        );

        // A rule wired again is meant to run: a step someone removed comes back with it.
        if ((int) $step->is_deleted === 1) {
            $step->forceFill(['is_deleted' => 0])->saveOrFail();
        }

        return $rule;
    }
}
