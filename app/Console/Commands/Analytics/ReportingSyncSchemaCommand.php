<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Apps\Models\Apps;
use Throwable;

/**
 * Bring an app's flat reporting tables in line with their definitions.
 *
 * Not a migration: the tables are per-app, so there is no fixed set to migrate. Safe to run on
 * every deploy — it emits only the difference.
 */
class ReportingSyncSchemaCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:reporting-sync-schema
                            {app_id : The application ID}
                            {--model= : Only this model; omit for all}
                            {--prune : Also DROP columns the definition no longer declares}
                            {--allow-narrowing : Apply type changes that shrink a column (truncates data)}
                            {--dry-run : Print the statements without running them}';

    protected $description = 'Create or evolve the flat reporting tables for an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        // Operates on one app; Bouncer scope leaks between runs without this.
        $this->overwriteAppService($app);

        $only = $this->option('model');
        $dryRun = (bool) $this->option('dry-run');
        $service = new ReportSchemaService();

        foreach (new ReportRegistry()->for($app) as $definition) {
            if ($only !== null && $definition->model() !== $only) {
                continue;
            }

            $table = $service->tableFor($definition, $app->getId());

            try {
                if ($dryRun) {
                    $this->line($service->tableExists($table)
                        ? implode(";\n", $service->alterStatements($definition, $table, false)) ?: "-- {$table} is up to date"
                        : $service->createStatement($definition, $table));

                    continue;
                }

                $statements = $service->sync(
                    $definition,
                    $app->getId(),
                    (bool) $this->option('prune'),
                    (bool) $this->option('allow-narrowing'),
                );

                $this->info(sprintf(
                    '%-28s %s',
                    $table,
                    $statements === [] ? 'up to date' : count($statements) . ' statement(s) applied'
                ));

                foreach ($statements as $sql) {
                    $this->line('  ' . str_replace("\n", "\n  ", $sql));
                }

                foreach ($service->skipped() as $skip) {
                    $this->warn('  skipped ' . $skip);
                }
            } catch (Throwable $e) {
                $this->error(sprintf('%s failed: %s', $table, $e->getMessage()));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
