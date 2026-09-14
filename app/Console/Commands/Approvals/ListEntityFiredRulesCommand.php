<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Illuminate\Console\Command;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;

/**
 * The exit criterion for the transitional entity-fired workflow event.
 *
 * ApproveAction fires approved/rejected on BOTH the ApprovalRequest row (permanent) and the target
 * entity (rollout compatibility, so rules a tenant already attached to a Bill keep working). The
 * entity fire is only safe to delete once no tenant rules are attached to entity system modules for
 * these events — this reports exactly that, so the decision is evidence rather than a guess.
 */
class ListEntityFiredRulesCommand extends Command
{
    protected $signature = 'kanvas:approvals:list-entity-fired-rules';

    protected $description = 'Lists workflow rules still bound to entity system modules for approval events';

    public function handle(): int
    {
        $ruleTypeIds = RuleType::query()
            ->whereIn('name', [WorkflowEnum::APPROVED->value, WorkflowEnum::REJECTED->value])
            ->pluck('id');

        if ($ruleTypeIds->isEmpty()) {
            $this->warn('No approved/rejected rule types exist yet — run the approval rule-type migration.');

            return self::SUCCESS;
        }

        $approvalModuleIds = SystemModules::query()
            ->where('model_name', ApprovalRequest::class)
            ->pluck('id');

        $rules = Rule::query()
            ->whereIn('rules_types_id', $ruleTypeIds)
            ->whereNotIn('systems_modules_id', $approvalModuleIds)
            ->where('is_deleted', 0)
            ->get();

        if ($rules->isEmpty()) {
            $this->info('No entity-fired approval rules remain. The transitional entity fireWorkflow call can be removed.');

            return self::SUCCESS;
        }

        $this->warn("{$rules->count()} rule(s) still depend on the entity-fired event:");

        $this->table(
            ['rule id', 'name', 'apps_id', 'companies_id', 'system module'],
            $rules->map(fn (Rule $rule): array => [
                $rule->getId(),
                $rule->name,
                $rule->apps_id,
                $rule->companies_id,
                SystemModules::find($rule->systems_modules_id)?->model_name ?? $rule->systems_modules_id,
            ])->all()
        );

        return self::SUCCESS;
    }
}
