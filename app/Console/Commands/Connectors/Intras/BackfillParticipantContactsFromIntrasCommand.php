<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\BackfillParticipantContactsFromIntrasAction;
use Throwable;

class BackfillParticipantContactsFromIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-backfill-participant-contacts
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {--limit= : Stop after this many already-imported people}
                            {--dry-run : Report what would be written without persisting}';

    protected $description = 'Backfill email/phone contacts for People already imported from Intras (legacy stores them in participants_custom_fields, original sync skipped them)';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Backfilling Intras participant contacts for company: {$company->name}");
        if ($dryRun) {
            $this->warn('Dry run — no Contact rows will be written.');
        }
        if ($limit !== null) {
            $this->info("Limit: {$limit}");
        }

        try {
            $action = new BackfillParticipantContactsFromIntrasAction(
                app: $app,
                company: $company,
                limit: $limit,
                dryRun: $dryRun,
                onProgress: function (int $scanned, int $updated): void {
                    $this->line("  scanned: {$scanned}  updated: {$updated}");
                },
            );

            $result = $action->execute();

            $this->info("Done. Scanned: {$result['scanned']}  Updated: {$result['updated']}");
        } catch (Throwable $e) {
            $this->error("Backfill failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
        }
    }
}
