<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * Idempotently wires "when {event} fires on {entity}, run {activity}" for an app (and optionally one
 * company). Returns null when the activity has no Action row yet: `kanvas:workflow-sync-actions`
 * creates those on deploy, and a setup command must say so rather than create a dangling rule.
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
                'pattern' => 1,
                'is_deleted' => 0,
            ]
        );

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $action->getId(),
            ],
            ['is_deleted' => 0]
        );

        RuleAction::firstOrCreate(
            [
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ],
            ['weight' => 0, 'is_deleted' => 0]
        );

        return $rule;
    }
}
