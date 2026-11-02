<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Concerns;

use Kanvas\Communication\Connections\Actions\ApplyIntegrationActiveAction;
use Kanvas\Communication\Connections\Actions\RetireConnectionAction;
use Kanvas\Communication\Connections\Actions\ScheduleCommunicationSetupAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The integration lifecycle of a handler that carries messages: saving it sets up a connection per
 * selected account, its switch reaches every connection on it, and removing it retires them. Only a
 * messaging handler uses this, so Workflow never has to know which integrations are communication.
 */
trait ProvisionsConnections
{
    public function saved(IntegrationsCompany $integrationCompany): void
    {
        new ScheduleCommunicationSetupAction($this->app, $integrationCompany)->execute();
    }

    public function activeChanged(IntegrationsCompany $integrationCompany, bool $active): void
    {
        new ApplyIntegrationActiveAction($this->app, $integrationCompany, $active)->execute();
    }

    public function removing(IntegrationsCompany $integrationCompany): void
    {
        Connection::query()
            ->live()
            ->where('integrations_company_id', $integrationCompany->getId())
            ->get()
            ->each(fn (Connection $connection) => new RetireConnectionAction($connection, 'integration_removed')->execute());
    }
}
