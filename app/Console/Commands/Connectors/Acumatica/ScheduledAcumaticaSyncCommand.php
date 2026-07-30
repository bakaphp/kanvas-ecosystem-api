<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Acumatica;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Jobs\SyncAcumaticaCompanyJob;
use Kanvas\Connectors\Acumatica\Services\AcumaticaSyncService;

/**
 * Cron entry point for the incremental Acumatica sync. Dispatches one SyncAcumaticaCompanyJob per
 * company that has explicitly opted in (ACUMATICA_SYNC_ENABLED) and is fully configured. Companies
 * that haven't been enabled are never touched.
 */
class ScheduledAcumaticaSyncCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:acumatica-sync';

    protected $description = 'Dispatch incremental Acumatica syncs for every opted-in company.';

    public function handle(): int
    {
        $sync = new AcumaticaSyncService();
        $dispatched = 0;
        $skipped = 0;

        /** @var Companies $company */
        foreach ($sync->enabledCompanies() as $company) {
            $target = $sync->resolveTarget($company);

            if ($target === null) {
                $this->warn("company {$company->getId()}: enabled but not runnable (missing app/company-id or SQL not configured) — skipped.");
                $skipped++;

                continue;
            }

            $this->overwriteAppService($target->app);

            SyncAcumaticaCompanyJob::dispatch(
                $target->app,
                $target->company,
                $target->user,
                $target->acumaticaCompanyId,
                $target->region,
            );
            $dispatched++;
        }

        $this->info("Acumatica sync dispatched for {$dispatched} company(ies), {$skipped} skipped.");

        return self::SUCCESS;
    }
}
