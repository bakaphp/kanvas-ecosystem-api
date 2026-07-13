<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

trait InteractsWithVinSolutionCompanies
{
    /**
     * Resolve the companies to process: an explicit comma-separated list, or —
     * when omitted — every company in the app that has a VinSolution dealer id
     * configured (mirrors the legacy getCompanyWithVinSolutions gate).
     *
     * @return Collection<int, Companies>
     */
    protected function resolveVinCompanies(Apps $app, ?string $companyIdsInput): Collection
    {
        if ($companyIdsInput !== null && $companyIdsInput !== '') {
            return collect(array_map('trim', explode(',', $companyIdsInput)))
                ->map(fn (string $id): Companies => Companies::getById((int) $id));
        }

        $companies = Companies::getByCustomFieldBuilder(ConfigurationEnum::COMPANY->value, null)
            ->whereIn(
                'companies.id',
                fn (QueryBuilder $query): QueryBuilder => $query->select('companies_id')
                    ->from('user_company_apps')
                    ->where('apps_id', $app->getId())
            )
            ->where('companies.is_deleted', 0)
            ->get();

        // A dealer id of "1" is the legacy "disabled" sentinel — skip it.
        $companies = $companies->filter(
            fn (Companies $company): bool => ! in_array(
                (string) $company->get(ConfigurationEnum::COMPANY->value),
                ['', '1'],
                true
            )
        )->values();

        $this->info('Auto-discovered ' . $companies->count() . ' VinSolution-configured companies for this app.');

        return $companies;
    }

    /**
     * The Kanvas user whose VinSolution credentials drive read calls for a company —
     * the opted-in DOWNLOAD_ALL_LEADS_USER when present, otherwise the company owner.
     */
    protected function resolveOperatingUser(Companies $company): ?Users
    {
        $downloadUserId = $company->get(ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);

        if (! empty($downloadUserId)) {
            return Users::getById((int) $downloadUserId);
        }

        return $company->user;
    }
}
