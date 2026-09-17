<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use RuntimeException;
use Throwable;

/**
 * Config keys (all under `config('nervous-system.ledger.*')`):
 *   retention_days, archive_disk, archive_path_prefix, archive_chunk_size, archive_events_per_file
 *
 * Each archive file's rows are deleted as soon as it uploads, so a crash mid-sweep keeps every earlier file's progress.
 */
class ArchiveOldEventsAction
{
    private Carbon $cutoff;
    private string $disk;
    private string $pathPrefix;
    private int $chunkSize;
    private int $eventsPerFile;

    /** @var list<string> */
    private array $preserveEventTypes;

    /**
     * @param list<string>|null $preserveEventTypesOverride
     */
    public function __construct(
        public readonly ?int $retentionDaysOverride = null,
        public readonly ?string $diskOverride = null,
        public readonly ?array $preserveEventTypesOverride = null,
    ) {
    }

    /**
     * @return array{event_count: int, archive_count: int, size_bytes: int, archive_ids: list<int>}
     */
    public function execute(): array
    {
        $retentionDays = $this->retentionDaysOverride
            ?? (int) config('nervous-system.ledger.retention_days', 7);
        $this->cutoff = now()->subDays($retentionDays);
        $this->disk = $this->diskOverride
            ?? (string) config('nervous-system.ledger.archive_disk', 's3');
        $this->pathPrefix = (string) config('nervous-system.ledger.archive_path_prefix', 'nervous-system');
        $this->chunkSize = max(1, (int) config('nervous-system.ledger.archive_chunk_size', 500));
        $this->eventsPerFile = max(1, (int) config('nervous-system.ledger.archive_events_per_file', 50000));
        $this->preserveEventTypes = $this->preserveEventTypesOverride
            ?? (array) config('nervous-system.ledger.preserve_event_types', []);

        $eventCount = 0;
        $sizeBytes = 0;
        $archiveIds = [];

        while (($archive = $this->archiveNextFile()) !== null) {
            $eventCount += $archive->event_count;
            $sizeBytes += (int) $archive->size_bytes;
            $archiveIds[] = $archive->id;
        }

        return [
            'event_count' => $eventCount,
            'archive_count' => count($archiveIds),
            'size_bytes' => $sizeBytes,
            'archive_ids' => $archiveIds,
        ];
    }

    private function eligibleEvents(): Builder
    {
        return Event::query()
            ->where('occurred_at', '<', $this->cutoff)
            ->when(
                $this->preserveEventTypes !== [],
                fn (Builder $q): Builder => $q->whereNotIn('event_type', $this->preserveEventTypes)
            );
    }

    private function archiveNextFile(): ?EventArchive
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ns-archive-');
        $gz = gzopen($tempFile, 'wb6');

        if ($gz === false) {
            @unlink($tempFile);

            throw new RuntimeException('Could not open temp file for gzip writing');
        }

        $written = 0;
        $firstId = null;
        $lastId = null;
        $windowStart = null;
        $windowEnd = null;

        try {
            $this->eligibleEvents()->chunkById(
                $this->chunkSize,
                function (Collection $events) use ($gz, &$written, &$firstId, &$lastId, &$windowStart, &$windowEnd): bool {
                    foreach ($events as $event) {
                        gzwrite($gz, json_encode($event->toArray()) . "\n");
                        $written++;
                        $firstId ??= $event->id;
                        $lastId = $event->id;

                        if ($windowStart === null || $event->occurred_at->lt($windowStart)) {
                            $windowStart = $event->occurred_at;
                        }
                        if ($windowEnd === null || $event->occurred_at->gt($windowEnd)) {
                            $windowEnd = $event->occurred_at;
                        }
                    }

                    return $written < $this->eventsPerFile;
                }
            );
        } catch (Throwable $e) {
            @unlink($tempFile);

            throw $e;
        } finally {
            gzclose($gz);
        }

        if ($written === 0) {
            @unlink($tempFile);

            return null;
        }

        $relativePath = sprintf(
            '%s/%s/%s/events-%s-to-%s-%s.jsonl.gz',
            $this->pathPrefix,
            $windowStart->format('Y'),
            $windowStart->format('m-d'),
            $windowStart->format('Ymd-His'),
            $windowEnd->format('Ymd-His'),
            substr((string) Str::uuid(), 0, 8),
        );

        $sizeBytes = filesize($tempFile);
        $stream = fopen($tempFile, 'rb');

        if ($stream === false) {
            @unlink($tempFile);

            throw new RuntimeException('Could not reopen temp archive for streaming upload');
        }

        try {
            Storage::disk($this->disk)->writeStream($relativePath, $stream);
        } finally {
            fclose($stream);
            @unlink($tempFile);
        }

        $archive = new EventArchive();
        $archive->apps_id = null;
        $archive->companies_id = null;
        $archive->window_starts_at = $windowStart->toDateString();
        $archive->window_ends_at = $windowEnd->toDateString();
        $archive->s3_disk = $this->disk;
        $archive->s3_path = $relativePath;
        $archive->event_count = $written;
        $archive->size_bytes = $sizeBytes !== false ? (int) $sizeBytes : null;
        $archive->archived_at = now();
        $archive->saveOrFail();

        // Bounded by the id range just written, so nothing that wasn't archived can be deleted.
        $deleted = $this->eligibleEvents()
            ->whereBetween('id', [$firstId, $lastId])
            ->toBase()
            ->delete();

        // The next file re-reads from the lowest eligible id; without a delete it would loop forever.
        if ($deleted === 0) {
            throw new RuntimeException("Archived {$relativePath} but deleted no ledger rows (ids {$firstId}-{$lastId})");
        }

        return $archive;
    }
}
