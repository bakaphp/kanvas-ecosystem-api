<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Baka\Contracts\BaseIntegration;
use Baka\Contracts\DefaultRule;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * Creates the rules an integration's handler declares in `defaultRules()` for the connected
 * company, each through EnsureDefaultRuleAction.
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
        return new EnsureDefaultRuleAction(
            $defaultRule,
            $this->app,
            $this->integrationCompany->company,
            $this->user,
            'Created when ' . $this->integrationCompany->integration->name . ' was connected',
        )->execute();
    }
}
