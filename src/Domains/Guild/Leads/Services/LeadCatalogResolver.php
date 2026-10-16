<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;

/**
 * Turns the type, status and source names a CRM reports into this company's catalog rows,
 * creating the row the first time a name is seen. A null name keeps the lead's current value:
 * CRMs that publish deltas omit what did not change, and overwriting it with nothing would wipe it.
 */
final class LeadCatalogResolver
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company
    ) {
    }

    public function typeId(?string $name, ?int $currentId = null, ?string $description = null): int
    {
        if ($name === null) {
            return (int) ($currentId ?? 0);
        }

        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'description' => $description ?? $name,
                'is_active' => 1,
            ],
        );

        return $type->getId();
    }

    public function statusId(?string $name, ?int $currentId = null): int
    {
        // Leaving leads_status_id = 0 breaks the LeadObserver, which calls status()->firstOrFail()
        // on save, so with nothing to go on the fallback is the first status visible to this app,
        // including globally-seeded rows with apps_id = 0.
        if ($name === null) {
            if ($currentId !== null) {
                return $currentId;
            }

            $default = LeadStatus::query()
                ->whereIn('apps_id', [0, $this->app->getId()])
                ->first();

            return $default?->getId() ?? 0;
        }

        $status = LeadStatus::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'is_default' => 0,
            ],
        );

        return $status->getId();
    }

    public function sourceId(?string $name, ?int $currentId = null, ?string $description = null): int
    {
        if ($name === null) {
            return (int) ($currentId ?? 0);
        }

        $source = LeadSource::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'description' => $description ?? $name,
                'is_active' => 1,
            ],
        );

        return $source->getId();
    }
}
