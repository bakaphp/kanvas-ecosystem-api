<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The trigger: completing a messaging integration is the only thing a tenant does. Called from the
 * handler's saved() hook, inside the transaction that saves the integration, so the connection rows
 * it writes commit with the selection they come from; the vendor work runs after commit, in a job.
 */
final class ScheduleCommunicationSetupAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly IntegrationsCompany $integrationCompany
    ) {
    }

    public function execute(): bool
    {
        $provider = new ProviderRegistry()->forIntegration($this->integrationCompany);

        if ($provider === null || (int) $this->integrationCompany->apps_id !== $this->app->getId()) {
            return false;
        }

        new EnsureCommunicationSetupAction($this->app, $this->integrationCompany)->reconcile($provider);

        // Live from the install for every provider, not once the setup job gets to it: a vendor
        // that checks the callback as soon as it is registered (Meta) fails on a missing receiver.
        new CommunicationReceivers()->ensure($this->app, $this->integrationCompany->company, $provider::slug());

        DB::connection('workflow')->afterCommit(
            fn () => EnsureCommunicationSetupJob::dispatch($this->app, $this->integrationCompany)
        );

        return true;
    }
}
