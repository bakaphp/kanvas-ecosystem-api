<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Support\ProviderRollout;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Actions\EnsureAppDefaultRulesAction;

/**
 * Brings an integration's connection rows in line with its accounts now, and queues the vendor
 * work. Runs wherever the accounts can change: the handler's saved() hook, an account linked without
 * the integration form, an agent given a number or a mailbox. Inside a transaction the rows commit
 * with the change they come from; the vendor work runs after commit, in a job.
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
        $provider = new ProviderRollout()->providerFor($this->integrationCompany, $this->app);

        if ($provider === null) {
            return false;
        }

        new EnsureCommunicationSetupAction($this->app, $this->integrationCompany)->reconcile($provider);
        // A company that connects its first channel answers through the app's rules.
        new EnsureAppDefaultRulesAction($this->app)->execute();

        // Live from the install for every provider, not once the setup job gets to it: a vendor
        // that checks the callback as soon as it is registered (Meta) fails on a missing receiver.
        new CommunicationReceivers()->ensure($this->app, $this->integrationCompany->company, $provider::slug());

        DB::connection('workflow')->afterCommit(
            fn () => EnsureCommunicationSetupJob::dispatch($this->app, $this->integrationCompany)
        );

        return true;
    }
}
