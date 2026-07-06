<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Throwable;

class CopyCompanyRulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'workflow:copy-company-rules
        {source_company_id : Company id to copy the rules from}
        {target_company_id : Company id to install the copied rules into}
        {--rule-ids= : Optional comma-separated rule ids to copy (defaults to every rule of the source company)}';

    protected $description = 'Copy rules (with their conditions and workflow activities) from one company to another';

    public function handle(): int
    {
        $sourceCompanyId = (int) $this->argument('source_company_id');
        $targetCompanyId = (int) $this->argument('target_company_id');
        $ruleIds = $this->parseRuleIds();

        $query = Rule::query()
            ->where('companies_id', $sourceCompanyId)
            ->where('is_deleted', 0)
            ->with(['getRulesConditions', 'workflowActivities'])
            ->orderBy('id', 'ASC');

        if (! empty($ruleIds)) {
            $query->whereIn('id', $ruleIds);
        }

        $rules = $query->get();

        if ($rules->isEmpty()) {
            $this->error("No rules found for company {$sourceCompanyId}" . (! empty($ruleIds) ? ' matching the given rule ids.' : '.'));

            return self::FAILURE;
        }

        /** @var Apps $app */
        $app = Apps::getById((int) $rules->first()->apps_id);
        $this->overwriteAppService($app);

        $this->info("Copying {$rules->count()} rule(s) from company {$sourceCompanyId} to company {$targetCompanyId} (app {$app->getId()})");

        $copied = 0;

        try {
            DB::connection('workflow')->transaction(function () use ($rules, $targetCompanyId, &$copied): void {
                foreach ($rules as $rule) {
                    $newRule = $this->copyRule($rule, $targetCompanyId);
                    $conditions = $this->copyConditions($rule, $newRule);
                    $activities = $this->copyActivities($rule, $newRule);
                    $copied++;

                    $this->line("  ✓ Rule '{$rule->name}' ({$rule->id} → {$newRule->id}) — {$conditions} condition(s), {$activities} activity(ies)");
                }
            });
        } catch (Throwable $e) {
            $this->error("Error copying rules: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        $this->info("✓ Copied {$copied} rule(s) into company {$targetCompanyId}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    protected function parseRuleIds(): array
    {
        $raw = $this->option('rule-ids');

        if (empty($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) $raw)
        )));
    }

    protected function copyRule(Rule $original, int $targetCompanyId): Rule
    {
        $newRule = new Rule();
        $newRule->systems_modules_id = $original->systems_modules_id;
        $newRule->companies_id = $targetCompanyId;
        $newRule->apps_id = $original->apps_id;
        $newRule->rules_types_id = $original->rules_types_id;
        $newRule->name = $original->name;
        $newRule->description = $original->description;
        $newRule->pattern = $original->pattern;
        $newRule->params = $original->params;
        $newRule->is_async = $original->is_async;
        $newRule->weight = $original->weight;
        $newRule->is_deleted = 0;
        $newRule->save();

        return $newRule;
    }

    protected function copyConditions(Rule $original, Rule $new): int
    {
        $count = 0;

        foreach ($original->getRulesConditions as $condition) {
            $newCondition = new RuleCondition();
            $newCondition->rules_id = $new->id;
            $newCondition->attribute_name = $condition->attribute_name;
            $newCondition->operator = $condition->operator;
            $newCondition->value = $condition->value;
            $newCondition->is_custom_attributes = $condition->is_custom_attributes;
            $newCondition->is_deleted = 0;
            $newCondition->save();
            $count++;
        }

        return $count;
    }

    /**
     * rules_workflow_actions rows are shared lookups (they map to global actions),
     * so we reuse the same rules_workflow_actions_id and only clone the pivot.
     */
    protected function copyActivities(Rule $original, Rule $new): int
    {
        $count = 0;

        foreach ($original->workflowActivities as $activity) {
            $newActivity = new RuleAction();
            $newActivity->rules_id = $new->id;
            $newActivity->rules_workflow_actions_id = $activity->rules_workflow_actions_id;
            $newActivity->weight = $activity->weight;
            $newActivity->is_deleted = 0;
            $newActivity->save();
            $count++;
        }

        return $count;
    }
}
