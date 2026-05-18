<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\KanvasModules\Enums\CompanyKanvasModuleStatusEnum;
use Kanvas\KanvasModules\Models\CompanyKanvasModule;
use Kanvas\KanvasModules\Models\KanvasModule;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class ProvisionDefaultConsumerModulesCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:provision-default-consumer-modules
        {app_id : The app id whose companies should be backfilled}
        {--reactivate-deleted : Also flip is_deleted=false / is_active=true on existing soft-deleted rows (status is preserved)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill companies_kanvas_modules for every company of an app with the default consumer modules (is_default=1, is_internal=0). Existing rows are left untouched unless --reactivate-deleted is passed.';

    public function handle(): int
    {
        $appId = (int) $this->argument('app_id');
        $reactivate = (bool) $this->option('reactivate-deleted');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $app = Apps::getById($appId);
        } catch (ModelNotFoundException) {
            warning("App id={$appId} not found.");

            return self::FAILURE;
        }

        info("App: {$app->name} (id={$app->getId()})");

        $eligibleModuleIds = DB::table('abilities_modules')
            ->where('apps_id', $app->getId())
            ->distinct()
            ->pluck('module_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($eligibleModuleIds === []) {
            warning('No abilities_modules rows for this app, nothing to provision.');

            return self::SUCCESS;
        }

        $moduleIds = KanvasModule::whereIn('id', $eligibleModuleIds)
            ->where('is_default', 1)
            ->where('is_internal', 0)
            ->where('is_deleted', 0)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($moduleIds === []) {
            warning('No default consumer modules registered for this app, nothing to provision.');

            return self::SUCCESS;
        }

        $this->line('  default consumer modules: ' . implode(',', $moduleIds));

        $totals = ['inserted' => 0, 'reactivated' => 0, 'skipped' => 0, 'companies' => 0];

        $appCompanyIds = DB::table('user_company_apps')
            ->select('companies_id')
            ->where('apps_id', $app->getId())
            ->distinct();

        Companies::query()
            ->whereIn('id', $appCompanyIds)
            ->orderBy('id')
            ->chunkById(
                200,
                function (SupportCollection $chunk) use ($app, $moduleIds, $reactivate, $dryRun, &$totals): void {
                    foreach ($chunk as $company) {
                        $totals['companies']++;

                        /** @var EloquentCollection<int, CompanyKanvasModule> $existingByModule */
                        $existingByModule = CompanyKanvasModule::where('apps_id', $app->getId())
                            ->where('companies_id', $company->getId())
                            ->whereIn('kanvas_modules_id', $moduleIds)
                            ->get()
                            ->keyBy('kanvas_modules_id');

                        foreach ($moduleIds as $moduleId) {
                            $existing = $existingByModule->get($moduleId);

                            if ($existing === null) {
                                if (! $dryRun) {
                                    CompanyKanvasModule::create([
                                        'apps_id' => $app->getId(),
                                        'companies_id' => $company->getId(),
                                        'kanvas_modules_id' => $moduleId,
                                        'is_active' => true,
                                        'is_deleted' => false,
                                        'status' => CompanyKanvasModuleStatusEnum::CONNECTED->value,
                                    ]);
                                }
                                $totals['inserted']++;

                                continue;
                            }

                            if ($reactivate && ($existing->is_deleted || ! $existing->is_active)) {
                                if (! $dryRun) {
                                    $existing->is_active = true;
                                    $existing->is_deleted = false;
                                    $existing->save();
                                }
                                $totals['reactivated']++;

                                continue;
                            }

                            $totals['skipped']++;
                        }
                    }
                },
            );

        $this->newLine();
        info(sprintf(
            'Summary: companies=%d inserted=%d reactivated=%d skipped=%d%s',
            $totals['companies'],
            $totals['inserted'],
            $totals['reactivated'],
            $totals['skipped'],
            $dryRun ? '  [DRY RUN]' : '',
        ));

        return self::SUCCESS;
    }
}
