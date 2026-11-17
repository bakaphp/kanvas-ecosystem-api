<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Baka\Contracts\DefaultRule;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Spatie\LaravelData\DataCollection;

/**
 * Idempotent: CreateRuleAction keys on name + type + module + app + company. A rule whose activity
 * is not in the `actions` catalog yet is skipped and logged rather than half-created: run
 * `kanvas:workflow-sync-actions` first.
 */
final class EnsureDefaultRuleAction
{
    public function __construct(
        private readonly DefaultRule $defaultRule,
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly UserInterface $user,
        private readonly string $description,
    ) {
    }

    public function execute(): ?Rule
    {
        $action = Action::forHandler($this->defaultRule->activity);

        if ($action === null || (int) $action->is_deleted === 1) {
            Log::warning('Default rule skipped: activity is not in the actions catalog', [
                'companies_id' => $this->company->getId(),
                'activity' => $this->defaultRule->activity,
                'rule' => $this->defaultRule->name,
            ]);

            return null;
        }

        try {
            $ruleType = RuleType::getByName($this->defaultRule->trigger->value);
            $systemModule = SystemModulesRepository::getByModelName($this->defaultRule->model, $this->app);
        } catch (ModelNotFoundException $e) {
            Log::warning('Default rule skipped: ' . $e->getMessage(), [
                'rule' => $this->defaultRule->name,
            ]);

            return null;
        }

        $rule = new CreateRuleAction(
            new RuleData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                ruleType: $ruleType,
                systemModule: $systemModule,
                name: $this->defaultRule->name,
                description: $this->description,
                params: $this->defaultRule->params,
                is_async: $this->defaultRule->isAsync,
                conditions: $this->defaultRule->conditions === [] ? null : RuleConditionData::collect($this->defaultRule->conditions, DataCollection::class),
                actions: RuleActionData::collect([new RuleActionData($action)], DataCollection::class),
            )
        )->execute();

        // A disconnect soft-deletes the rule and firstOrCreate finds it again, so a reconnect restores it.
        if ((int) $rule->is_deleted === 1) {
            $rule->is_deleted = 0;
            $rule->saveOrFail();
        }

        return $rule;
    }
}
