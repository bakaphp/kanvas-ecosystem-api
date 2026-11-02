<?php

declare(strict_types=1);

namespace App\Console\Commands\Insurance;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Insurance\Jobs\BuildVehicleModelCrosswalkJob;

class BuildVehicleModelCrosswalkCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:insurance:build-vehicle-crosswalk
        {--app= : Restrict to a single apps_id}
        {--company= : Company holding the insurer credentials; defaults to the app B2B main company}';

    protected $description = 'Queue the offline build of the canonical vehicle to insurer model crosswalk.';

    public function handle(): int
    {
        $apps = Apps::query()
            ->where('is_deleted', 0)
            ->when($this->option('app') !== null, fn ($query) => $query->where('id', (int) $this->option('app')))
            ->orderBy('id')
            ->get();

        $dispatched = 0;

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            $company = $this->credentialCompany($app);

            if ($company === null) {
                $this->warn(sprintf('app=%d skipped: no credential company', $app->getId()));

                continue;
            }

            BuildVehicleModelCrosswalkJob::dispatch($app, $company);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d crosswalk job(s).', $dispatched));

        return self::SUCCESS;
    }

    private function credentialCompany(Apps $app): ?Companies
    {
        $companyId = $this->option('company') !== null
            ? (int) $this->option('company')
            : (int) $app->get('B2B_MAIN_COMPANY_ID');

        return $companyId > 0 ? Companies::query()->find($companyId) : null;
    }
}
