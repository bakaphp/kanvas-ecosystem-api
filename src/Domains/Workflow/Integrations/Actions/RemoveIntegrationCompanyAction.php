<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\RetireConnectionAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

final class RemoveIntegrationCompanyAction
{
    /**
     * A company can live in several apps, so the app has to come from the caller — and it has to be
     * the row's own, or one app could disconnect another app's integration.
     */
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app
    ) {
    }

    public function execute(): bool
    {
        if (! $this->integrationCompany->isManageableFrom($this->app)) {
            throw new ModelNotFoundException('Integration not found for this app.');
        }

        $this->retireConnections();
        $this->teardownHandler();

        return (bool) $this->integrationCompany->delete();
    }

    /**
     * Before the handler's teardown deletes anything: the vendor cleanup the retirement schedules
     * still needs the credentials to remove what setup registered.
     */
    private function retireConnections(): void
    {
        Connection::query()
            ->live()
            ->where('integrations_company_id', $this->integrationCompany->getId())
            ->get()
            ->each(fn (Connection $connection) => new RetireConnectionAction($connection, 'integration_removed')->execute());
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
