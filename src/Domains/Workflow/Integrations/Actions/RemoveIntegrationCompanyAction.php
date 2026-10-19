<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Actions\RemoveIntegrationDefaultRulesAction;

final class RemoveIntegrationCompanyAction
{
    /**
     * integration_companies carries no apps_id and a company can live in several apps, so the
     * app has to come from the caller.
     */
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app
    ) {
    }

    public function execute(): bool
    {
        new RemoveIntegrationDefaultRulesAction($this->integrationCompany, $this->app)->execute();
        $this->teardownHandler();

        return (bool) $this->integrationCompany->delete();
    }

    private function teardownHandler(): void
    {
        BaseIntegration::forIntegrationCompany($this->integrationCompany, $this->app)?->teardown();
    }
}
