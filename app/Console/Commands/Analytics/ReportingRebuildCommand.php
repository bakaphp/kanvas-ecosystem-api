<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\Services\ReportRefreshService;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Throwable;

/**
 * Rebuild an app's flat reporting rows from Kanvas.
 *
 * Full rebuild is the reconciliation pass — it is the only run that can tell "deleted at source"
 * from "not in this batch", so it is the only one that prunes.
 */
class ReportingRebuildCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:reporting-rebuild
                            {app_id : The application ID}
                            {--company= : Only this company; omit for every company with rows}
                            {--model= : Only this model; omit for all}
                            {--no-prune : Keep rows the source no longer produces}';

    protected $description = 'Rebuild the flat reporting tables for an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        // Operates on one app; Bouncer scope leaks between runs without this.
        $this->overwriteAppService($app);

        $registry = new ReportRegistry();
        $schema = new ReportSchemaService();
        $refresh = new ReportRefreshService($schema);

        $definitions = $registry->for($app);

        if ($definitions === []) {
            $this->warn('No reporting models registered for app ' . $app->getId());

            return self::SUCCESS;
        }

        foreach ($this->companies($app) as $company) {
            foreach ($definitions as $definition) {
                if ($this->option('model') !== null && $definition->model() !== $this->option('model')) {
                    continue;
                }

                if (! $definition instanceof RefreshableReportInterface) {
                    $this->line(sprintf('  %-14s skipped — no refresh implementation yet', $definition->model()));

                    continue;
                }

                $startedAt = date('Y-m-d H:i:s');

                try {
                    $schema->sync($definition, $app->getId());
                    $written = $refresh->refresh($definition, $app, $company);

                    $pruned = $this->option('no-prune')
                        ? 0
                        : $refresh->pruneStale($definition, $app, $company, $startedAt);

                    $this->info(sprintf(
                        '  company %-8d %-14s %d rows, %d stale removed',
                        $company->getId(),
                        $definition->model(),
                        $written,
                        $pruned
                    ));
                } catch (Throwable $e) {
                    $this->error(sprintf('  company %d %s failed: %s', $company->getId(), $definition->model(), $e->getMessage()));

                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Companies>
     */
    protected function companies(Apps $app): array
    {
        if ($this->option('company') !== null) {
            return [Companies::getById((int) $this->option('company'))];
        }

        // Every company that actually has people on this app — deriving it beats maintaining a
        // list, and an app with no data is then a no-op rather than an error.
        //
        // `peoples` lives on `crm` while Companies is on `ecosystem`, so the subquery has to be
        // schema-qualified or MySQL looks for it in the wrong database.
        $companyIds = DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->distinct()
            ->pluck('companies_id')
            ->all();

        if ($companyIds === []) {
            return [];
        }

        return Companies::query()->whereIn('id', $companyIds)->get()->all();
    }
}
