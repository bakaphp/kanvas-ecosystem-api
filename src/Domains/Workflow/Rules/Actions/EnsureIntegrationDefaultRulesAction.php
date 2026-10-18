<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Contracts\DefaultRule;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Spatie\LaravelData\DataCollection;

/**
 * Creates the rules an integration's handler declares in `defaultRules()` for the connected
 * company. Idempotent: CreateRuleAction keys on name + type + module + app + company. A rule whose
 * activity is not in the `actions` catalog yet is skipped and logged rather than half-created — run
 * `kanvas:workflow-sync-actions` first.
 */
final class EnsureIntegrationDefaultRulesAction
{
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app,
        private readonly UserInterface $user,
    ) {
    }

    /**
     * @return list<Rule>
     */
    public function execute(): array
    {
        $handler = BaseIntegration::forIntegrationCompany($this->integrationCompany, $this->app);

        if ($handler === null) {
            return [];
        }

        $rules = [];

        foreach ($handler->defaultRules() as $defaultRule) {
            $rule = $this->ensure($defaultRule);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function ensure(DefaultRule $defaultRule): ?Rule
    {
        $action = Action::forHandler($defaultRule->activity);

        if ($action === null || (int) $action->is_deleted === 1) {
            Log::warning('Integration default rule skipped: activity is not in the actions catalog', [
                'integration' => $this->integrationCompany->integration?->name,
                'companies_id' => $this->integrationCompany->companies_id,
                'activity' => $defaultRule->activity,
                'rule' => $defaultRule->name,
            ]);

            return null;
        }

        try {
            $ruleType = RuleType::getByName($defaultRule->trigger->value);
            $systemModule = SystemModulesRepository::getByModelName($defaultRule->model, $this->app);
        } catch (ModelNotFoundException $e) {
            Log::warning('Integration default rule skipped: ' . $e->getMessage(), [
                'integration' => $this->integrationCompany->integration?->name,
                'rule' => $defaultRule->name,
            ]);

            return null;
        }

        $rule = new CreateRuleAction(
            new RuleData(
                app: $this->app,
                company: $this->integrationCompany->company,
                user: $this->user,
                ruleType: $ruleType,
                systemModule: $systemModule,
                name: $defaultRule->name,
                description: 'Created when ' . $this->integrationCompany->integration->name . ' was connected',
                params: $defaultRule->params,
                is_async: $defaultRule->isAsync,
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
