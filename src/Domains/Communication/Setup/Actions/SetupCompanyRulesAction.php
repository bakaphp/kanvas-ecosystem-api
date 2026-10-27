<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Communication\Setup\Enums\DefaultRuleEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum as GoogleConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use Spatie\LaravelData\DataCollection;

/**
 * The company's default workflow rules, last of the setup because a rule acts the moment it exists.
 * A rule is skipped when one already runs the same activities on the same event for this company or
 * for the whole app, so nothing ever runs twice; and when what it needs is not there yet (a sender
 * number, Google Calendar).
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
        $catalog = new WorkflowActionCatalog();

        foreach (DefaultRuleEnum::cases() as $default) {
            $params = $this->params($default);

            if ($params === null) {
                $skipped[$default->value] = 'not_configured';

                continue;
            }

            $ruleType = RuleType::getByName($default->event()->value);
            $systemModule = SystemModulesRepository::getByModelName($default->module(), $this->app);
            $actions = array_map($catalog->register(...), $default->activities());

            $alreadyRuns = $this->alreadyRuns(
                $ruleType,
                $systemModule->getId(),
                array_map(fn (Action $action): int => $action->getId(), $actions),
                $default
            );

            if ($alreadyRuns) {
                $skipped[$default->value] = 'already_runs';

                continue;
            }

            $conditions = $default->conditions();

            new CreateRuleAction(new RuleData(
                app: $this->app,
                company: $this->company,
                user: $this->company->user,
                ruleType: $ruleType,
                systemModule: $systemModule,
                name: $default->value,
                pattern: $conditions === [] ? '1' : implode(' AND ', range(1, count($conditions))),
                params: $params,
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

            $created[] = $default->value;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>|null null when the company lacks what the rule needs to run
     */
    private function params(DefaultRuleEnum $default): ?array
    {
        return match ($default) {
            DefaultRuleEnum::AI_ASSIST_RESPONDER => ($polly = DefaultAgentEnum::POLLY->of($this->app, $this->company)) !== null
                ? ['agent_id' => $polly->getId()]
                : null,
            // It sends through the lead's legacy SMS route, which needs the number to send from.
            DefaultRuleEnum::HUMAN_AGENT_RESPONSE => ($from = $this->company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value))
                ? ['from' => $from]
                : null,
            DefaultRuleEnum::GOOGLE_CALENDAR_EVENT => $this->company->get(GoogleConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value)
                ? []
                : null,
            default => $default->params(),
        };
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
                ->map(fn ($ruleAction) => (int) $ruleAction->activity?->actions_id)
                ->all();

            if (array_diff($actionIds, $ruleActionIds) === []) {
                return true;
            }
        }

        return false;
    }
}
