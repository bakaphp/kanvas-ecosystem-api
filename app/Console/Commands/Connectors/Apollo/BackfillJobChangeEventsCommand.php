<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\BackfillJobChangeEventAction;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * Backfill `people.enriched` ledger events for job changes Apollo recorded BEFORE
 * the ledger emission existed. Those historical moves only survive as the
 * `APOLLO_LAST_JOB_CHANGE` config blob on each person — this replays them into the
 * ledger so the "Registro de cambios" feed shows them with real Antes → Después.
 *
 * Selection mirrors ReportJobChangesCommand; the per-person diff / dedup / emit lives
 * in BackfillJobChangeEventAction. Only genuine moves are emitted and a per-person
 * ledger guard makes re-runs no-ops — no ledger schema change.
 */
class BackfillJobChangeEventsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-backfill-job-change-events {app_id} {company_id} {--from= : Only changes on/after this date (Y-m-d)} {--to= : Only changes on/before this date (Y-m-d)} {--dry-run : Report what would be emitted without writing}';

    /**
     * @var string|null
     */
    protected $description = 'Backfill people.enriched ledger events from historical Apollo job changes (APOLLO_LAST_JOB_CHANGE)';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $dryRun = (bool) $this->option('dry-run');

        $query = People::getByCustomFieldBuilder(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value, null, $company)
            ->fromApp($app)
            ->notDeleted(0);

        if ($from !== '') {
            $query->where('apps_custom_fields.value', '>=', strtotime($from));
        }

        if ($to !== '') {
            $query->where('apps_custom_fields.value', '<=', strtotime($to . ' 23:59:59'));
        }

        $people = $query->orderBy('apps_custom_fields.value', 'desc')->get();

        $this->line("Found {$people->count()} people with a recorded job change for company {$company->name}");

        $emitted = 0;
        $skipped = 0;

        foreach ($people as $person) {
            $result = new BackfillJobChangeEventAction($person, $app, $company)->execute($dryRun);

            if ($result === BackfillJobChangeEventAction::EMITTED || $result === BackfillJobChangeEventAction::WOULD_EMIT) {
                $emitted++;
                $this->line("[{$result}] people {$person->id}");

                continue;
            }

            $skipped++;
        }

        $verb = $dryRun ? 'would emit' : 'emitted';
        $this->line("Done: {$verb} {$emitted}, skipped {$skipped}");

        return self::SUCCESS;
    }
}
