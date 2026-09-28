<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\BackfillParticipantProfileFieldsFromIntrasAction;
use Throwable;

class BackfillParticipantProfileFieldsFromIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-backfill-participant-profile-fields
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {--limit= : Stop after this many already-imported people}
                            {--dry-run : Report what would be written without persisting}';

    protected $description = 'Backfill nivel/area (and other profile attributes) onto People already imported from Intras';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Backfilling Intras participant profile fields for company: {$company->name}");
        if ($dryRun) {
            $this->warn('Dry run — no custom fields will be written.');
        }
        if ($limit !== null) {
            $this->info("Limit: {$limit}");
        }

        try {
            $action = new BackfillParticipantProfileFieldsFromIntrasAction(
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

            if ($result['fields'] === []) {
                $this->warn('No profile values found. Run kanvas:intras-inspect-participant-fields to confirm the legacy field names.');

                return;
            }

            foreach ($result['fields'] as $name => $count) {
                $this->line("  {$name}: {$count}");
            }
        } catch (Throwable $e) {
            $this->error("Backfill failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
        }
    }
}
