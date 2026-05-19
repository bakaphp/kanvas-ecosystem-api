<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use RuntimeException;

/**
 * Config keys (all under `config('nervous-system.ledger.*')`):
 *   retention_days, archive_disk, archive_path_prefix, archive_chunk_size
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

        $window = Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->selectRaw('count(*) as event_count, min(occurred_at) as window_start, max(occurred_at) as window_end')
            ->first();

        if ($window === null || (int) $window->event_count === 0) {
            return ['event_count' => 0];
        }

        $startCarbon = Carbon::parse($window->window_start);
        $endCarbon = Carbon::parse($window->window_end);

        $relativePath = sprintf(
            '%s/%s/%s/events-%s-to-%s-%s.jsonl.gz',
            $pathPrefix,
            $startCarbon->format('Y'),
            $startCarbon->format('m-d'),
            $startCarbon->format('Ymd-His'),
            $endCarbon->format('Ymd-His'),
            substr((string) Str::uuid(), 0, 8),
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'ns-archive-') . '.jsonl.gz';
        $gz = gzopen($tempFile, 'wb9');

        if ($gz === false) {
            throw new RuntimeException('Could not open temp file for gzip writing');
        }

        $written = 0;

        Event::query()
            ->where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($events) use ($gz, &$written): void {
                foreach ($events as $event) {
                    $line = json_encode($event->toArray()) . "\n";
                    gzwrite($gz, $line);
                    $written++;
                }
            });

        gzclose($gz);

        $sizeBytes = filesize($tempFile);

        $stream = fopen($tempFile, 'rb');

        if ($stream === false) {
            @unlink($tempFile);

            throw new RuntimeException('Could not reopen temp archive for streaming upload');
        }

        try {
            Storage::disk($disk)->writeStream($relativePath, $stream);
        } finally {
            fclose($stream);
            @unlink($tempFile);
        }

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
            ->delete();

        return [
            'archive_id' => $archive->id,
            'event_count' => $written,
            's3_path' => $relativePath,
            'size_bytes' => $sizeBytes !== false ? (int) $sizeBytes : 0,
        ];
    }
}
