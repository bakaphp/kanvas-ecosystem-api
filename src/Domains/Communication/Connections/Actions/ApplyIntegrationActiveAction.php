<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * What the integration's switch means for every connection on it — without touching any connection's
 * `enabled`, so a number the person switched off stays off when the integration comes back. Runs only
 * on an actual transition, inside the transaction that moved the switch — the same rule as a
 * connection's own On.
 */
final class ApplyIntegrationActiveAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly IntegrationsCompany $integrationCompany,
        private readonly bool $active
    ) {
    }

    public function execute(): void
    {
        $connections = Connection::query()
            ->live()
            ->where('integrations_company_id', $this->integrationCompany->getId());

        if (! $this->active) {
            SetConnectionEnabledAction::skipPending($connections->pluck('id')->all(), 'integration_inactive');

            return;
        }

        // Work queued while the integration was off carries the old version, so it is never released.
        $connections->update(['activation_version' => DB::raw('activation_version + 1')]);

        DB::connection('workflow')->afterCommit(
            fn () => EnsureCommunicationSetupJob::dispatch($this->app, $this->integrationCompany)
        );
    }
}
