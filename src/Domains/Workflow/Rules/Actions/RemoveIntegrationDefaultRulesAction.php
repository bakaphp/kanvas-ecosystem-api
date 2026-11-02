<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;

/**
 * Soft-deletes the rules EnsureIntegrationDefaultRulesAction created for this company, matched by
 * the same identity (name + type + module + app + company). A rule the tenant renamed or created
 * by hand is left alone.
 */
final class RemoveIntegrationDefaultRulesAction
{
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app,
    ) {
    }

    public function execute(): int
    {
        $handler = BaseIntegration::forIntegrationCompany($this->integrationCompany, $this->app);

        if ($handler === null) {
            return 0;
        }

        $removed = 0;

        foreach ($handler->defaultRules() as $defaultRule) {
            try {
                $ruleType = RuleType::getByName($defaultRule->trigger->value);
                $systemModule = SystemModulesRepository::getByModelName($defaultRule->model, $this->app);
            } catch (ModelNotFoundException) {
                continue;
            }

            $removed += Rule::query()
                ->where('name', $defaultRule->name)
                ->where('rules_types_id', $ruleType->getId())
                ->where('systems_modules_id', $systemModule->getId())
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->integrationCompany->companies_id)
                ->where('is_deleted', 0)
                ->update(['is_deleted' => 1]);
        }

        return $removed;
    }
}
