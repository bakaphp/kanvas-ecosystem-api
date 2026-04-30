<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;

/**
 * Sweeps events older than the configured retention window into a
 * gzipped JSONL blob on the configured filesystem disk, records the
 * archive in nervous_system_event_archives, and deletes the rows
 * from MySQL.
 *
 * Idempotent across runs (archives only the events present at sweep
 * start). Runs in chunks so memory stays bounded.
 *
 * Configuration:
 *   config('nervous-system.ledger.retention_days')
 *   config('nervous-system.ledger.archive_disk')
 *   config('nervous-system.ledger.archive_path_prefix')
 *   config('nervous-system.ledger.archive_chunk_size')
 */
class ArchiveOldEventsAction
{
    public function __construct(
        public readonly ?int $retentionDaysOverride = null,
        public readonly ?string $diskOverride = null,
    ) {
    }

    /**
     * @return array{archive_id: int, event_count: int, s3_path: string, size_bytes: int}|array{event_count: 0}
     */
    public function execute(): array
    {
        $retentionDays = $this->retentionDaysOverride
            ?? (int) config('nervous-system.ledger.retention_days', 7);
        $disk = $this->diskOverride
            ?? (string) config('nervous-system.ledger.archive_disk', 's3');
        $pathPrefix = (string) config('nervous-system.ledger.archive_path_prefix', 'nervous-system');
        $chunkSize = (int) config('nervous-system.ledger.archive_chunk_size', 5000);

        $cutoff = now()->subDays($retentionDays);

        $eligibleCount = Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->where('is_archived', 0)
            ->count();

        if ($eligibleCount === 0) {
            return ['event_count' => 0];
        }

        $windowStart = Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->where('is_archived', 0)
            ->min('occurred_at');
        $windowEnd = Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->where('is_archived', 0)
            ->max('occurred_at');

        $startCarbon = Carbon::parse($windowStart);
        $endCarbon = Carbon::parse($windowEnd);

        $relativePath = sprintf(
            '%s/%s/week-%s/events-%s-to-%s-%s.jsonl.gz',
            $pathPrefix,
            $startCarbon->format('Y'),
            $startCarbon->format('W'),
            $startCarbon->format('Ymd-His'),
            $endCarbon->format('Ymd-His'),
            substr((string) Str::uuid(), 0, 8),
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'ns-archive-') . '.jsonl.gz';
        $gz = gzopen($tempFile, 'wb9');

        if ($gz === false) {
            throw new \RuntimeException('Could not open temp file for gzip writing');
        }

        $written = 0;

        Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->where('is_archived', 0)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($events) use ($gz, &$written): void {
                foreach ($events as $event) {
                    $line = json_encode($event->toArray()) . "\n";
                    gzwrite($gz, $line);
                    $written++;
                }
            });

        gzclose($gz);

        Storage::disk($disk)->put($relativePath, file_get_contents($tempFile));
        $sizeBytes = filesize($tempFile);
        @unlink($tempFile);

        $archive = new EventArchive();
        $archive->apps_id = null;
        $archive->companies_id = null;
        $archive->window_starts_at = $startCarbon->toDateString();
        $archive->window_ends_at = $endCarbon->toDateString();
        $archive->s3_disk = $disk;
        $archive->s3_path = $relativePath;
        $archive->event_count = $written;
        $archive->size_bytes = $sizeBytes !== false ? (int) $sizeBytes : null;
        $archive->archived_at = now();
        $archive->saveOrFail();

        DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('occurred_at', '<', $cutoff)
            ->where('is_archived', 0)
            ->delete();

        return [
            'archive_id' => $archive->id,
            'event_count' => $written,
            's3_path' => $relativePath,
            'size_bytes' => $sizeBytes !== false ? (int) $sizeBytes : 0,
        ];
    }
}
