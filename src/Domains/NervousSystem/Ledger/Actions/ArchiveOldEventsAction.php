<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use RuntimeException;

/**
 * Config keys (all under `config('nervous-system.ledger.*')`):
 *   retention_days, archive_disk, archive_path_prefix, archive_chunk_size, archive_segment_size
 *
 * The sweep is split into segments: every `archive_segment_size` events become their own blob and
 * `EventArchive` row, and are deleted from MySQL as soon as that blob is uploaded. A run that dies
 * midway (OOM, deploy, timeout) keeps every finished segment instead of re-archiving the whole
 * backlog tomorrow — a single end-of-run delete let one failure snowball the table day over day.
 */
class ArchiveOldEventsAction
{
    private readonly string $disk;
    private readonly string $pathPrefix;
    private readonly int $chunkSize;
    private readonly int $segmentSize;

    /** @var resource|null */
    private $gz = null;
    private ?string $tempFile = null;

    /** @var list<int> */
    private array $segmentIds = [];
    private ?Carbon $segmentStart = null;
    private ?Carbon $segmentEnd = null;

    /** @var list<array{archive_id: int, s3_path: string, event_count: int, size_bytes: int}> */
    private array $archives = [];

    /**
     * @param list<string>|null $preserveEventTypesOverride
     */
    public function __construct(
        public readonly ?int $retentionDaysOverride = null,
        public readonly ?string $diskOverride = null,
        public readonly ?array $preserveEventTypesOverride = null,
        public readonly ?int $segmentSizeOverride = null,
    ) {
        $this->disk = $this->diskOverride
            ?? (string) config('nervous-system.ledger.archive_disk', 's3');
        $this->pathPrefix = (string) config('nervous-system.ledger.archive_path_prefix', 'nervous-system');
        $this->chunkSize = max(1, (int) config('nervous-system.ledger.archive_chunk_size', 500));
        $this->segmentSize = max(1, $this->segmentSizeOverride ?? (int) config('nervous-system.ledger.archive_segment_size', 50000));
    }

    /**
     * @return array{event_count: int, size_bytes: int, archives: list<array{archive_id: int, s3_path: string, event_count: int, size_bytes: int}>}
     */
    public function execute(): array
    {
        $retentionDays = $this->retentionDaysOverride
            ?? (int) config('nervous-system.ledger.retention_days', 7);
        $preserveEventTypes = $this->preserveEventTypesOverride
            ?? (array) config('nervous-system.ledger.preserve_event_types', []);

        $cutoff = now()->subDays($retentionDays);

        try {
            Event::query()
                ->where('occurred_at', '<', $cutoff)
                ->when($preserveEventTypes !== [], fn (Builder $q): Builder => $q->whereNotIn('event_type', $preserveEventTypes))
                ->chunkById($this->chunkSize, function (Collection $events): void {
                    foreach ($events as $event) {
                        $this->write($event);

                        if (count($this->segmentIds) >= $this->segmentSize) {
                            $this->flushSegment();
                        }
                    }
                });

            $this->flushSegment();
        } finally {
            $this->discardOpenSegment();
        }

        return [
            'event_count' => array_sum(array_column($this->archives, 'event_count')),
            'size_bytes' => array_sum(array_column($this->archives, 'size_bytes')),
            'archives' => $this->archives,
        ];
    }

    private function write(Event $event): void
    {
        if ($this->gz === null) {
            $tempFile = tempnam(sys_get_temp_dir(), 'ns-archive-');
            $gz = $tempFile !== false ? gzopen($tempFile, 'wb9') : false;

            if ($gz === false) {
                throw new RuntimeException('Could not open temp file for gzip writing');
            }

            $this->tempFile = $tempFile;
            $this->gz = $gz;
        }

        gzwrite($this->gz, json_encode($event->toArray()) . "\n");

        $this->segmentIds[] = $event->id;

        if ($this->segmentStart === null || $event->occurred_at->lt($this->segmentStart)) {
            $this->segmentStart = $event->occurred_at->copy();
        }

        if ($this->segmentEnd === null || $event->occurred_at->gt($this->segmentEnd)) {
            $this->segmentEnd = $event->occurred_at->copy();
        }
    }

    private function flushSegment(): void
    {
        if ($this->gz === null || $this->segmentIds === []) {
            return;
        }

        gzclose($this->gz);
        $this->gz = null;

        $relativePath = sprintf(
            '%s/%s/%s/events-%s-to-%s-%s.jsonl.gz',
            $this->pathPrefix,
            $this->segmentStart->format('Y'),
            $this->segmentStart->format('m-d'),
            $this->segmentStart->format('Ymd-His'),
            $this->segmentEnd->format('Ymd-His'),
            substr((string) Str::uuid(), 0, 8),
        );

        $sizeBytes = (int) filesize($this->tempFile);
        $stream = fopen($this->tempFile, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not reopen temp archive for streaming upload');
        }

        try {
            Storage::disk($this->disk)->writeStream($relativePath, $stream);
        } finally {
            fclose($stream);
        }

        $archive = new EventArchive();
        $archive->apps_id = null;
        $archive->companies_id = null;
        $archive->window_starts_at = $this->segmentStart->toDateString();
        $archive->window_ends_at = $this->segmentEnd->toDateString();
        $archive->s3_disk = $this->disk;
        $archive->s3_path = $relativePath;
        $archive->event_count = count($this->segmentIds);
        $archive->size_bytes = $sizeBytes;
        $archive->archived_at = now();
        $archive->saveOrFail();

        foreach (array_chunk($this->segmentIds, $this->chunkSize) as $ids) {
            DB::connection('intelligence')
                ->table('nervous_system_events')
                ->whereIn('id', $ids)
                ->delete();
        }

        $this->archives[] = [
            'archive_id' => $archive->id,
            's3_path' => $relativePath,
            'event_count' => count($this->segmentIds),
            'size_bytes' => $sizeBytes,
        ];

        $this->discardOpenSegment();
    }

    private function discardOpenSegment(): void
    {
        if ($this->gz !== null) {
            gzclose($this->gz);
            $this->gz = null;
        }

        if ($this->tempFile !== null) {
            @unlink($this->tempFile);
            $this->tempFile = null;
        }

        $this->segmentIds = [];
        $this->segmentStart = null;
        $this->segmentEnd = null;
    }
}
