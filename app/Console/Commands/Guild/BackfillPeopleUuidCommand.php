<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;

/**
 * Give a uuid to People rows written by an importer that used `saveQuietly()`.
 *
 * `saveQuietly()` is `withoutEvents()`, so `UuidTrait`'s `creating` hook never ran and the rows
 * landed with a null uuid. The importers now call `generateUuidIfMissing()` themselves; this
 * repairs what they already wrote.
 *
 * Deliberately a raw chunked UPDATE rather than Eloquent: touching the model would fire the
 * observers and Scout indexing the original import specifically avoided, and re-indexing tens of
 * thousands of people is not what a uuid repair should do.
 */
class BackfillPeopleUuidCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:backfill-people-uuid
                            {apps_id : App to backfill}
                            {--companies_id= : Limit to one company}
                            {--chunk=1000 : Rows per chunk}
                            {--dry-run : Report the count without writing}';

    protected $description = 'Fill the uuid on People rows an importer created with saveQuietly()';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $companyId = $this->option('companies_id') === null ? null : (int) $this->option('companies_id');
        $chunk = (int) $this->option('chunk');

        $pending = fn () => DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $app->getId())
            ->where(fn ($q) => $q->whereNull('uuid')->orWhere('uuid', ''))
            ->when($companyId !== null, fn ($q) => $q->where('companies_id', $companyId));

        $total = $pending()->count();

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d people would get a uuid.', $total));

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $updated = 0;

        // Re-querying rather than paging: each pass shrinks the candidate set, so there is no
        // cursor to invalidate and an interrupted run resumes correctly.
        while (true) {
            $ids = $pending()->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                DB::connection('crm')
                    ->table('peoples')
                    ->where('id', $id)
                    ->update(['uuid' => (string) Str::uuid7()]);

                $updated++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Backfilled %d people.', $updated));

        return self::SUCCESS;
    }
}
