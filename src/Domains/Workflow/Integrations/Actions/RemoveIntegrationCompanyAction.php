<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

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
        $this->teardownHandler();

        return (bool) $this->integrationCompany->delete();
    }

    private function teardownHandler(): void
    {
        $handler = $this->integrationCompany->integration?->handler;

        if (! is_string($handler) || ! is_subclass_of($handler, BaseIntegration::class)) {
            return;
        }

        new $handler(
            app: $this->app,
            company: $this->integrationCompany->company,
            region: $this->integrationCompany->region,
            data: [],
            integration: $this->integrationCompany->integration,
        )->teardown();
    }
}
