<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution\Concerns;

use App\Console\Commands\Connectors\Concerns\ResolvesConfiguredCompanies;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Services\LeadUserService;
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

    protected function resolveOperatingUser(Companies $company): ?Users
    {
        return LeadUserService::operatingUser($company);
    }
}
