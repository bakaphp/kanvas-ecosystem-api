<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Setup\Enums\DefaultRuleEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use Spatie\LaravelData\DataCollection;

/**
 * The workflow rules setup still gives a company, last of the setup because a rule acts the moment
 * it exists. A rule is skipped when one already runs the same activities on the same event for this
 * company or for the whole app, so nothing ever runs twice; and when what it needs is not there yet
 * (Google Calendar).
 */
final class SetupCompanyRulesAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company
    ) {
    }

    /**
     * @return array{created: list<string>, skipped: array<string, string>} rule names, and why a rule was skipped
     */
    public function execute(): array
    {
        $created = [];
        $skipped = [];

        foreach (DefaultRuleEnum::cases() as $default) {
            $reason = $this->ensure($default);

            if ($reason === null) {
                $created[] = $default->value;
            } else {
                $skipped[$default->value] = $reason;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return string|null why the rule was skipped, null when it was created
     */
    private function ensure(DefaultRuleEnum $default): ?string
    {
        $requiredSetting = $default->requiredCompanySetting();

        if ($requiredSetting !== null && ! $this->company->get($requiredSetting)) {
            return 'not_configured';
        }

        $ruleType = RuleType::getByName($default->event()->value);
        $systemModule = SystemModulesRepository::getByModelName($default->module(), $this->app);
        $actions = array_map(new WorkflowActionCatalog()->register(...), $default->activities());

        $alreadyRuns = $this->alreadyRuns(
            $ruleType,
            $systemModule->getId(),
            array_map(fn (Action $action): int => $action->getId(), $actions),
            $default
        );

        if ($alreadyRuns) {
            return 'already_runs';
        }

        $conditions = $default->conditions();

        new CreateRuleAction(new RuleData(
            app: $this->app,
            company: $this->company,
            user: $this->company->user,
            ruleType: $ruleType,
            systemModule: $systemModule,
            name: $default->value,
            pattern: implode(' AND ', range(1, count($conditions))),
            params: [],
            is_async: true,
            conditions: RuleConditionData::collect(array_map(
                fn (array $condition): RuleConditionData => new RuleConditionData(...$condition),
                $conditions
            ), DataCollection::class),
            actions: RuleActionData::collect(array_map(
                fn (Action $action, int $weight): RuleActionData => new RuleActionData($action, $weight),
                $actions,
                array_keys($actions)
            ), DataCollection::class),
        ))->execute();

        return null;
    }

    /**
     * @param list<int> $actionIds
     */
    private function alreadyRuns(
        RuleType $ruleType,
        int $systemModuleId,
        array $actionIds,
        DefaultRuleEnum $default
    ): bool {
        $rules = Rule::query()
            ->fromApp($this->app)
            ->whereIn('companies_id', [$this->company->getId(), 0])
            ->where('rules_types_id', $ruleType->getId())
            ->where('systems_modules_id', $systemModuleId)
            ->notDeleted()
            ->with('workflowActivities.activity')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->name === $default->value && (int) $rule->companies_id === $this->company->getId()) {
                return true;
            }

            $ruleActionIds = $rule->workflowActivities
                ->where('is_deleted', 0)
                ->map(fn (RuleAction $ruleAction): int => (int) $ruleAction->activity?->actions_id)
                ->all();

            if (array_diff($actionIds, $ruleActionIds) === []) {
                return true;
            }
        }

        return false;
    }
}
