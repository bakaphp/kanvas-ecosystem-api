<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

trait WiresAppWorkflowRules
{
    protected function wireAppRule(
        Apps $app,
        int $systemModuleId,
        string $event,
        string $name,
        array $activities
    ): bool {
        $ruleType = RuleType::firstOrCreate(['name' => $event], ['is_deleted' => 0]);

        $rule = Rule::firstOrCreate(
            [
                'name' => $name,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModuleId,
                'apps_id' => $app->getId(),
                'companies_id' => 0,
            ],
            [
                'description' => 'Wired by ' . $this->getName(),
                'pattern' => 1,
                'params' => [],
                'is_deleted' => 0,
            ]
        );

        if ($rule->is_deleted) {
            $rule->update(['is_deleted' => 0]);
        }

        $allWired = true;

        foreach ($activities as $weight => $activity) {
            $action = Action::where('model_name', $activity)->first();

            if ($action === null) {
                $this->error(class_basename($activity) . ' has no action row; run `php artisan kanvas:workflow-sync-actions` first');
                $allWired = false;

                continue;
            }

            $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
                ['system_modules_id' => $systemModuleId, 'actions_id' => $action->getId()],
                ['is_deleted' => 0]
            );

            RuleAction::firstOrCreate(
                ['rules_id' => $rule->getId(), 'rules_workflow_actions_id' => $ruleWorkflowAction->getId()],
                ['weight' => $weight, 'is_deleted' => 0]
            );
        }

        $this->info("Rule #{$rule->getId()} on {$event}: " . implode(', ', array_map('class_basename', $activities)));

        return $allWired;
    }
}
