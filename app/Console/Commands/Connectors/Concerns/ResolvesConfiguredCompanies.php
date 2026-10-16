<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

trait ResolvesConfiguredCompanies
{
    /**
     * An explicit comma-separated list, or — when omitted — every company in the app
     * with the connector's company custom field set. "1" is the legacy "disabled" sentinel.
     *
     * @return Collection<int, Companies>
     */
    protected function resolveConfiguredCompanies(Apps $app, ?string $companyIdsInput, string $configKey): Collection
    {
        if ($companyIdsInput !== null && $companyIdsInput !== '') {
            return collect(array_map('trim', explode(',', $companyIdsInput)))
                ->map(fn (string $id): Companies => Companies::getById((int) $id));
        }

        $companies = Companies::getByCustomFieldBuilder($configKey, null)
            ->whereIn(
                'companies.id',
                fn (QueryBuilder $query): QueryBuilder => $query->select('companies_id')
                    ->from('user_company_apps')
                    ->where('apps_id', $app->getId())
            )
            ->where('companies.is_deleted', 0)
            ->get()
            ->filter(
                fn (Companies $company): bool => ! in_array(
                    (string) $company->get($configKey),
                    ['', '1'],
                    true
                )
            )
            ->values();

        $this->info('Auto-discovered ' . $companies->count() . ' configured companies for this app.');

        return $companies;
    }
}
