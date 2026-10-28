<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Support\ProviderRegistry;

/**
 * Health is its own fact: a connection that set up fine can start failing when credentials are
 * rotated or the account is suspended. Read-only, and never changes setup status or the switch.
 */
final class ProbeConnectionsAction
{
    private const int PROBE_EVERY_HOURS = 24;

    public function __construct(
        private readonly Apps $app
    ) {
    }

    public function execute(): int
    {
        $probed = 0;

        $connections = Connection::query()
            ->fromApp($this->app)
            ->where('setup_status', SetupStatusEnum::READY->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_probed_at')
                ->orWhere('last_probed_at', '<=', now()->subHours(self::PROBE_EVERY_HOURS)))
            ->with('integrationCompany')
            ->get()
            ->groupBy('integrations_company_id');

        $registry = new ProviderRegistry();
        $registered = $registry->all();

        foreach ($connections as $sameIntegration) {
            $first = $sameIntegration->first();

            // A provider taken out of the config leaves its rows behind; there is nothing to probe
            // them with, and one of them must not stop the sweep for every other tenant.
            if ($first->integrationCompany === null || ! isset($registered[$first->provider])) {
                continue;
            }

            $error = $registry
                ->provisioner($first->provider)
                ->probe($first->integrationCompany, $this->app);

            Connection::query()->whereKey($sameIntegration->modelKeys())->update([
                'health' => $error === null ? 'healthy' : 'failing',
                'last_error' => $error,
                'last_probed_at' => now(),
            ]);

            $probed += $sameIntegration->count();
        }

        return $probed;
    }
}
