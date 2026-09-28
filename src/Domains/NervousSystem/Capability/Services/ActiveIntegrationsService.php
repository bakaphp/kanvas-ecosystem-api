<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The external services one company has switched on.
 *
 * A row here is written by `IntegrationsMutation`, which validates the config and runs the connector's
 * own `setup()` before stamping it — so its presence means a connection that was actually proven,
 * which is why this and not `ConnectorReadinessService` is what everything should converge on.
 *
 * Company alone is the scope: `integration_companies` has no `apps_id`, and the integrations it points
 * at are global.
 */
class ActiveIntegrationsService
{
    public function __construct(
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * @return Collection<int, IntegrationsCompany>
     */
    public function rows(): Collection
    {
        return IntegrationsCompany::query()
            ->fromCompany($this->company)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->with(['integration', 'status'])
            ->get()
            ->filter(fn (IntegrationsCompany $row): bool => $row->integration !== null)
            ->values();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->rows()
            ->map(fn (IntegrationsCompany $row): string => (string) $row->integration->name)
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function describe(): array
    {
        return $this->rows()
            ->map(fn (IntegrationsCompany $row): array => [
                'name' => (string) $row->integration->name,
                'status' => (string) ($row->status->name ?? 'unknown'),
            ])
            ->all();
    }
}
