<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Ledger\Actions\ArchiveOldEventsAction;
use Throwable;

class ArchiveOldLedgerEventsCommand extends Command
{
    protected $signature = 'nervous-system:archive-old-ledger-events
        {--retention-days= : Override config retention window (useful in tests)}
        {--disk= : Override the archive disk (useful in tests)}';

    protected $description = 'Archive Nervous System ledger events older than the retention window to long-term storage and prune them from MySQL';

    public function handle(): int
    {
        $retentionDaysOpt = $this->option('retention-days');
        $diskOpt = $this->option('disk');

        $retentionDays = $retentionDaysOpt !== null ? (int) $retentionDaysOpt : null;
        $disk = is_string($diskOpt) ? $diskOpt : null;

        $this->info(sprintf(
            'Archiving ledger events older than %d days to disk "%s"',
            $retentionDays ?? (int) config('nervous-system.ledger.retention_days', 7),
            $disk ?? (string) config('nervous-system.ledger.archive_disk', 's3'),
        ));

        try {
            $result = new ArchiveOldEventsAction(
                retentionDaysOverride: $retentionDays,
                diskOverride: $disk,
            )->execute();
        } catch (Throwable $e) {
            $this->error('Archive failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result['event_count'] === 0) {
            $this->info('No events eligible for archival.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Archived %d events to %s (%d bytes), archive id %d.',
            $result['event_count'],
            $result['s3_path'],
            $result['size_bytes'],
            $result['archive_id'],
        ));

        return self::SUCCESS;
    }
}
