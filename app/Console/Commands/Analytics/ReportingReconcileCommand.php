<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Throwable;

/**
 * Does the flat table still agree with the source?
 *
 * The failure mode a flat table has is not an exception — it is a number that is quietly wrong.
 * A refresh job that was dropped, a fan-out that missed a dependency, a row deleted at source
 * that nothing pruned: none of those raise anything, they just leave the Gestor and the agent
 * confidently reporting a total nobody can reproduce.
 *
 * So this compares row counts and staleness per model and exits non-zero on drift, which is what
 * makes it useful to a monitor rather than only to a human reading output.
 */
class ReportingReconcileCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:reporting-reconcile
                            {app_id : The application ID}
                            {--company= : Only this company}
                            {--model= : Only this model}
                            {--stale-hours=36 : Flag rows not refreshed within this window}
                            {--fail-on-drift : Exit non-zero when anything is off, for monitoring}';

    protected $description = 'Compare flat reporting tables against their source and report drift';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $registry = new ReportRegistry();
        $schema = new ReportSchemaService();
        $definitions = $registry->for($app);

        if ($definitions === []) {
            $this->warn('No reporting models registered for app ' . $app->getId());

            return self::SUCCESS;
        }

        $staleBefore = date('Y-m-d H:i:s', time() - ((int) $this->option('stale-hours') * 3600));
        $drift = false;
        $rows = [];

        foreach ($this->companies($app) as $company) {
            foreach ($definitions as $definition) {
                if ($this->option('model') !== null && $definition->model() !== $this->option('model')) {
                    continue;
                }

                if (! $definition instanceof RefreshableReportInterface) {
                    continue;
                }

                try {
                    $table = $schema->tableFor($definition, $app->getId());

                    if (! $schema->tableExists($table)) {
                        $rows[] = [$company->getId(), $definition->model(), '—', '—', '—', 'TABLE MISSING'];
                        $drift = true;

                        continue;
                    }

                    $flat = $schema->connection()->table($table)
                        ->where(ReportSchemaService::TENANT_COLUMN, $company->getId())
                        ->count();

                    $stale = $schema->connection()->table($table)
                        ->where(ReportSchemaService::TENANT_COLUMN, $company->getId())
                        ->where('refreshed_at', '<', $staleBefore)
                        ->count();

                    // Counting the generator is the only source-of-truth available without
                    // duplicating each definition's query here — it is the same code the refresh
                    // runs, which is the point.
                    $source = 0;

                    foreach ($definition->rowsFor($app, $company) as $ignored) {
                        $source++;
                    }

                    $diff = $flat - $source;
                    $status = match (true) {
                        $diff !== 0 => 'DRIFT',
                        $stale > 0 => 'STALE',
                        default => 'ok',
                    };

                    if ($status !== 'ok') {
                        $drift = true;
                    }

                    $rows[] = [$company->getId(), $definition->model(), $source, $flat, $stale, $status];
                } catch (Throwable $e) {
                    $rows[] = [$company->getId(), $definition->model(), '—', '—', '—', 'ERROR: ' . $e->getMessage()];
                    $drift = true;
                }
            }
        }

        $this->table(['company', 'model', 'source', 'flat', 'stale', 'status'], $rows);

        if ($drift) {
            $this->warn('Drift detected — run kanvas:reporting-rebuild for the affected models.');
        } else {
            $this->info('All reporting tables reconcile with their source.');
        }

        return $drift && $this->option('fail-on-drift') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, Companies>
     */
    protected function companies(Apps $app): array
    {
        if ($this->option('company') !== null) {
            return [Companies::getById((int) $this->option('company'))];
        }

        // `peoples` is on `crm` while Companies is on `ecosystem`, so this cannot be a subquery.
        $companyIds = DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->distinct()
            ->pluck('companies_id')
            ->all();

        return $companyIds === [] ? [] : Companies::query()->whereIn('id', $companyIds)->get()->all();
    }
}
