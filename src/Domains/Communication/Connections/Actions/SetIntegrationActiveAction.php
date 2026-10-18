<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The integration's own switch, which every connection on it answers to — without touching any
 * connection's `enabled`, so a number the person switched off stays off when the integration comes
 * back.
 */
final class SetIntegrationActiveAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly IntegrationsCompany $integrationCompany,
        private readonly bool $active
    ) {
    }

    public function execute(): bool
    {
        $transitioned = DB::connection('workflow')->transaction(function (): bool {
            $changed = IntegrationsCompany::query()
                ->whereKey($this->integrationCompany->getKey())
                ->where('is_active', ! $this->active)
                ->update(['is_active' => $this->active]) === 1;

            // Only a messaging integration has connections; for any other this changes nothing.
            if (! $changed) {
                return false;
            }

            $connections = Connection::query()
                ->live()
                ->where('integrations_company_id', $this->integrationCompany->getId());

            // Same rule as a connection's On: the version moves only on an actual transition, so
            // work queued while the integration was off is never released by switching it back.
            $this->active
                ? $connections->update(['activation_version' => DB::raw('activation_version + 1')])
                : SetConnectionEnabledAction::skipPending($connections->pluck('id')->all(), 'integration_inactive');

            return true;
        });

        $this->integrationCompany->refresh();

        if ($transitioned && $this->active && app(ProviderRegistry::class)->forIntegration($this->integrationCompany) !== null) {
            EnsureCommunicationSetupJob::dispatch($this->app, $this->integrationCompany);
        }

        return (bool) $this->integrationCompany->is_active;
    }
}
