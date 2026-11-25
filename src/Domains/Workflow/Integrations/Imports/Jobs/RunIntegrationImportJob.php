<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChunkResult;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Importers\SingleRunImporter;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use RuntimeException;
use Throwable;

/**
 * One chunk per job, each queuing the next: a large import outlives any worker timeout, a chunk does
 * not. Chunks stay sequential because the run's counters are read-modify-write.
 */
class RunIntegrationImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int CHUNK_SIZE = 100;
    private const int ITEMS_TTL_HOURS = 24;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public IntegrationImport $run,
        public int $offset = 0,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->run->app);

        $importer = $this->importer();
        $options = $this->run->options ?? [];

        if ($importer instanceof SingleRunImporter) {
            $this->run->markRunning(null);
            $this->run->complete($importer->run($options));

            return;
        }

        if (! $importer instanceof ChunkedImporter) {
            throw new RuntimeException('An importer must be a chunked or a single run importer.');
        }

        if ($this->offset > 0 && ! $this->stillImportable()) {
            $this->forgetItemsFrom($this->chunkNumber());
            $this->run->fail('The integration was disconnected during the import.');

            return;
        }

        $items = $this->offset === 0 ? $this->listItems($importer, $options) : $this->cachedChunk();

        $chunk = new ImportChunkResult();
        foreach ($items as $index => $item) {
            try {
                $chunk->add($importer->importOne($item, $options));
            } catch (Throwable $e) {
                $chunk->fail($this->labelFor($importer, $item, $this->offset + $index), $e->getMessage());
            }
        }

        $this->run->advance($chunk);
        Cache::forget($this->chunkKey($this->chunkNumber()));

        $next = $this->offset + self::CHUNK_SIZE;

        if ($next >= (int) $this->run->total) {
            $this->complete($importer);

            return;
        }

        $this->refreshItemsFrom($this->chunkNumber() + 1);
        dispatch(new self($this->run, $next));
    }

    public function failed(Throwable $exception): void
    {
        $this->run->fail($exception->getMessage());
    }

    protected function importer(): IntegrationImporter
    {
        return $this->run->integrationCompany->importerFor(
            $this->run->entity,
            Users::getById($this->run->users_id)
        );
    }

    /**
     * A record too malformed to name must still fail alone, so an importer's itemId() is never
     * allowed to take the run down with it.
     */
    private function labelFor(ChunkedImporter $importer, mixed $item, int $position): string
    {
        try {
            return $importer->itemId($item);
        } catch (Throwable) {
            return '#' . $position;
        }
    }

    /**
     * The run is marked finished before completed() runs, so a follow-up import it starts is not
     * blocked by this one. A follow-up that blows up is reported, but the records are already in, so
     * the run stays completed and says what went wrong.
     */
    private function complete(ChunkedImporter $importer): void
    {
        $this->run->complete();

        try {
            $summary = $importer->completed($this->run);
        } catch (Throwable $e) {
            report($e);
            $summary = ['follow_up_error' => $e->getMessage()];
        }

        if ($summary !== null) {
            $this->run->complete($summary);
        }
    }

    private function stillImportable(): bool
    {
        $integrationCompany = $this->run->integrationCompany;

        return $integrationCompany !== null
            && ! $integrationCompany->is_deleted
            && $integrationCompany->isImportable();
    }

    /**
     * Each chunk is cached under its own key so a continuation unserialises its 100 records, not the
     * whole list. The first chunk is imported straight from memory and never cached.
     */
    private function listItems(ChunkedImporter $importer, array $options): array
    {
        $chunks = array_chunk($importer->list($options), self::CHUNK_SIZE);

        foreach (array_slice($chunks, 1, preserve_keys: true) as $number => $chunk) {
            Cache::put($this->chunkKey($number), $chunk, now()->addHours(self::ITEMS_TTL_HOURS));
        }

        $this->run->markRunning(array_sum(array_map('count', $chunks)));

        return $chunks[0] ?? [];
    }

    private function cachedChunk(): array
    {
        return Cache::get($this->chunkKey($this->chunkNumber()))
            ?? throw new RuntimeException(
                'The ' . $this->run->entity . ' import lost its record list. Start the import again.'
            );
    }

    /**
     * The TTL runs from the last chunk, not from the listing, so a long run does not outlive its
     * own records.
     */
    private function refreshItemsFrom(int $number): void
    {
        foreach (range($number, $this->lastChunkNumber()) as $remaining) {
            Cache::touch($this->chunkKey($remaining), now()->addHours(self::ITEMS_TTL_HOURS));
        }
    }

    private function forgetItemsFrom(int $number): void
    {
        foreach (range($number, max($number, $this->lastChunkNumber())) as $remaining) {
            Cache::forget($this->chunkKey($remaining));
        }
    }

    private function chunkNumber(): int
    {
        return intdiv($this->offset, self::CHUNK_SIZE);
    }

    private function lastChunkNumber(): int
    {
        return intdiv(max((int) $this->run->total - 1, 0), self::CHUNK_SIZE);
    }

    private function chunkKey(int $number): string
    {
        return 'integration-import-items:' . $this->run->getId() . ':' . $number;
    }
}
