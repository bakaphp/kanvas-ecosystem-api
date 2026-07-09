<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\RestoreEventsFromArchiveAction;
use Throwable;

class RestoreLedgerEventsFromArchiveCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'nervous-system:restore-ledger-events-from-archive
        {--event-type=* : Restrict to these event types (repeatable). Empty restores every type in the archive}
        {--app_id= : Only restore events for this app}
        {--company_id= : Only restore events for this company}
        {--archive-id= : Restore from a single EventArchive row instead of every archive}
        {--from= : Only restore events with occurred_at on/after this date (bare date = start of day)}
        {--to= : Only restore events with occurred_at on/before this date (bare date = end of day)}
        {--disk= : Override the archive disk (defaults to each archive\'s stored disk)}
        {--dry-run : Report what would be restored without writing}';

    protected $description = 'Re-hydrate archived Nervous System ledger events (e.g. people.enriched) back into MySQL from cold storage';

    public function handle(): int
    {
        $appId = $this->option('app_id') !== null ? (int) $this->option('app_id') : null;
        $companyId = $this->option('company_id') !== null ? (int) $this->option('company_id') : null;
        $archiveId = $this->option('archive-id') !== null ? (int) $this->option('archive-id') : null;
        $diskOption = $this->option('disk');
        $disk = is_string($diskOption) ? $diskOption : null;
        $fromOption = $this->option('from');
        $from = is_string($fromOption) ? $fromOption : null;
        $toOption = $this->option('to');
        $to = is_string($toOption) ? $toOption : null;

        if ($appId !== null) {
            /** @var Apps $app */
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);
        }

        /** @var list<string> $eventTypes */
        $eventTypes = array_values(array_filter((array) $this->option('event-type')));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s restore of %s events%s%s',
            $dryRun ? 'DRY-RUN' : 'Running',
            $eventTypes === [] ? 'all' : implode(', ', $eventTypes),
            $appId !== null ? " for app {$appId}" : '',
            $companyId !== null ? " company {$companyId}" : '',
        ));

        try {
            $result = new RestoreEventsFromArchiveAction(
                eventTypes: $eventTypes,
                appId: $appId,
                companyId: $companyId,
                diskOverride: $disk,
                archiveId: $archiveId,
                dryRun: $dryRun,
                fromDate: $from,
                toDate: $to,
            )->execute();
        } catch (Throwable $e) {
            $this->error('Restore failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[%s] scanned %d archives, %d candidates, restored %d, skipped %d already present, %d missing blobs.',
            $dryRun ? 'DRY-RUN' : 'APPLIED',
            $result['archives_scanned'],
            $result['candidates'],
            $result['restored'],
            $result['skipped_existing'],
            $result['missing_blobs'],
        ));

        return self::SUCCESS;
    }
}
