<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution\Concerns;

use App\Console\Commands\Connectors\Concerns\ResolvesConfiguredCompanies;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

trait InteractsWithVinSolutionCompanies
{
    use ResolvesConfiguredCompanies;

    /**
     * @return Collection<int, Companies>
     */
    protected function resolveVinCompanies(Apps $app, ?string $companyIdsInput): Collection
    {
        return $this->resolveConfiguredCompanies($app, $companyIdsInput, ConfigurationEnum::COMPANY->value);
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
