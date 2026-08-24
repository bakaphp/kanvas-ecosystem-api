<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Customers\Actions\UpdatePeopleDriverLicenseAction;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Models\SystemModules;
use Throwable;

class BackfillPeopleDriverLicenseCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:backfill-people-driver-license
                            {apps_id : App to backfill}
                            {--chunk=500 : Rows per chunk}
                            {--overwrite : Replace license values that are already set}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill the peoples license columns from the get_docs_drivers_license custom field';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        // `apps_custom_fields` has no apps_id — fromApp() on the People load does the scoping.
        AppsCustomFields::query()
            ->whereIn('model_name', [People::class, SystemModules::getLegacyNamespace(People::class)])
            ->whereIn('name', ['get_docs_drivers_license', 'drivers_license_number'])
            ->where('is_deleted', 0)
            ->chunkById((int) $this->option('chunk'), function ($rows) use ($app, $overwrite, $dryRun, &$updated, &$skipped, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $people = People::query()
                            ->where('id', (int) $row->entity_id)
                            ->fromApp($app)
                            ->notDeleted()
                            ->first();

                        if ($people === null) {
                            $skipped++;

                            continue;
                        }

                        $license = $row->name === 'get_docs_drivers_license'
                            ? DriverLicense::fromScan(is_array($row->value) ? $row->value : null)
                            : (is_string($row->value) && $row->value !== '' ? new DriverLicense(number: $row->value) : null);

                        if ($license === null) {
                            $skipped++;

                            continue;
                        }

                        $action = new UpdatePeopleDriverLicenseAction(
                            people: $people,
                            license: $license,
                            overwrite: $overwrite,
                            quietly: true,
                        );

                        if ($dryRun) {
                            $columns = $action->preview();

                            if ($columns === []) {
                                $skipped++;

                                continue;
                            }

                            $this->line("people {$people->getId()}: " . implode(', ', $columns));
                            $updated++;

                            continue;
                        }

                        $action->execute() ? $updated++ : $skipped++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("custom field {$row->getId()}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "updated: {$updated}, skipped: {$skipped}, failed: {$failed}");

        return self::SUCCESS;
    }
}
