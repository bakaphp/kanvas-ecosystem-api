<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use RuntimeException;

/**
 * Re-hydrate ledger events that the archive sweeper flushed to cold storage
 * back into MySQL. Meant for durable-feed event types (e.g. `people.enriched`)
 * that were swept before `preserve_event_types` protected them. Idempotent:
 * rows already present (matched by uuid) are skipped, so re-runs are safe.
 */
class RestoreEventsFromArchiveAction
{
    private readonly ?Carbon $from;
    private readonly ?Carbon $to;

    /**
     * @param list<string> $eventTypes restrict to these event types (empty = every type in the archive)
     * @param string|null  $fromDate   inclusive lower bound on occurred_at (bare date → start of that day)
     * @param string|null  $toDate     inclusive upper bound on occurred_at (bare date → end of that day)
     */
    public function __construct(
        public readonly array $eventTypes = [],
        public readonly ?int $appId = null,
        public readonly ?int $companyId = null,
        public readonly ?string $diskOverride = null,
        public readonly ?int $archiveId = null,
        public readonly bool $dryRun = false,
        ?string $fromDate = null,
        ?string $toDate = null,
    ) {
        $this->from = $fromDate !== null ? self::boundary($fromDate, isUpper: false) : null;
        $this->to = $toDate !== null ? self::boundary($toDate, isUpper: true) : null;
    }

    /**
     * Parse a user date/timestamp. A bare `Y-m-d` widens to the whole day so
     * `--from=2026-06-25 --to=2026-06-25` captures that entire day; an explicit
     * timestamp is honored as-is.
     */
    private static function boundary(string $value, bool $isUpper): Carbon
    {
        $carbon = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return $isUpper ? $carbon->endOfDay() : $carbon->startOfDay();
        }

        return $carbon;
    }

    /**
     * @return array{archives_scanned: int, candidates: int, restored: int, skipped_existing: int, missing_blobs: int}
     */
    public function execute(): array
    {
        $archivesScanned = 0;
        $candidates = 0;
        $restored = 0;
        $skippedExisting = 0;
        $missingBlobs = 0;

        $from = $this->from;
        $to = $this->to;

        EventArchive::query()
            ->when($this->archiveId !== null, fn (Builder $q): Builder => $q->where('id', $this->archiveId))
            // Skip archive windows that can't overlap the requested range. Coarse
            // (day-granular window bounds); the precise cut is per-row on occurred_at.
            ->when($from !== null, fn (Builder $q): Builder => $q->where('window_ends_at', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q): Builder => $q->where('window_starts_at', '<=', $to->toDateString()))
            ->orderBy('id')
            ->each(function (EventArchive $archive) use (
                &$archivesScanned,
                &$candidates,
                &$restored,
                &$skippedExisting,
                &$missingBlobs
            ): void {
                $archivesScanned++;
                $disk = $this->diskOverride ?? $archive->s3_disk;

                if (! Storage::disk($disk)->exists($archive->s3_path)) {
                    $missingBlobs++;

                    return;
                }

                $rows = $this->readMatchingRows($disk, $archive->s3_path);
                $candidates += count($rows);

                foreach (array_chunk($rows, 500, true) as $chunk) {
                    $existing = Event::query()
                        ->whereIn('uuid', array_keys($chunk))
                        ->pluck('uuid')
                        ->all();
                    $existingSet = array_flip($existing);

                    foreach ($chunk as $uuid => $attributes) {
                        if (isset($existingSet[$uuid])) {
                            $skippedExisting++;

                            continue;
                        }

                        $restored++;

                        if (! $this->dryRun) {
                            $this->insertRow($attributes);
                        }
                    }
                }
            });

        return [
            'archives_scanned' => $archivesScanned,
            'candidates' => $candidates,
            'restored' => $restored,
            'skipped_existing' => $skippedExisting,
            'missing_blobs' => $missingBlobs,
        ];
    }

    /**
     * Stream the gzipped JSONL blob line by line (never loads the compressed
     * bytes fully into memory) and return the rows that match the filters,
     * keyed by uuid so a blob can't yield the same event twice.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readMatchingRows(string $disk, string $path): array
    {
        $source = Storage::disk($disk)->readStream($path);

        if ($source === null) {
            throw new RuntimeException("Could not open archive stream for {$path}");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'ns-restore-') . '.gz';
        $sink = fopen($tempFile, 'wb');

        if ($sink === false) {
            fclose($source);
            @unlink($tempFile);

            throw new RuntimeException('Could not open temp file for archive download');
        }

        try {
            stream_copy_to_stream($source, $sink);
        } finally {
            fclose($sink);
            fclose($source);
        }

        $rows = [];
        $gz = gzopen($tempFile, 'rb');

        if ($gz === false) {
            @unlink($tempFile);

            throw new RuntimeException("Could not gunzip archive {$path}");
        }

        try {
            while (($line = gzgets($gz)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row) || ! isset($row['uuid'])) {
                    continue;
                }

                if (! $this->matches($row)) {
                    continue;
                }

                $rows[(string) $row['uuid']] = $row;
            }
        } finally {
            gzclose($gz);
            @unlink($tempFile);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row): bool
    {
        if ($this->eventTypes !== [] && ! in_array($row['event_type'] ?? null, $this->eventTypes, true)) {
            return false;
        }

        if ($this->appId !== null && (int) ($row['apps_id'] ?? 0) !== $this->appId) {
            return false;
        }

        if ($this->companyId !== null && (int) ($row['companies_id'] ?? 0) !== $this->companyId) {
            return false;
        }

        if (($this->from !== null || $this->to !== null) && isset($row['occurred_at'])) {
            $occurredAt = Carbon::parse((string) $row['occurred_at']);

            if ($this->from !== null && $occurredAt->lt($this->from)) {
                return false;
            }

            if ($this->to !== null && $occurredAt->gt($this->to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function insertRow(array $attributes): void
    {
        unset($attributes['id']);

        $event = new Event();
        $event->forceFill($attributes);
        // saveQuietly skips the creating hook so the archived category/change_count
        // land verbatim instead of being recomputed, and keeps the restore silent
        // (no broadcasts / observers re-firing for events that already happened).
        $event->saveQuietly();
    }
}
