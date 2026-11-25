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

        $items = $this->offset === 0 ? $this->listItems($importer, $options) : $this->cachedItems();

        $chunk = new ImportChunkResult();
        foreach (array_slice($items, $this->offset, self::CHUNK_SIZE) as $item) {
            try {
                $chunk->add($importer->importOne($item, $options));
            } catch (Throwable $e) {
                $chunk->fail($importer->itemId($item), $e->getMessage());
            }
        }

        $this->run->advance($chunk);

        $next = $this->offset + self::CHUNK_SIZE;

        if ($next >= count($items)) {
            $this->run->complete($importer->completed($this->run));
            Cache::forget($this->cacheKey());

            return;
        }

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

    private function listItems(ChunkedImporter $importer, array $options): array
    {
        $items = $importer->list($options);

        Cache::put($this->cacheKey(), $items, now()->addHours(self::ITEMS_TTL_HOURS));
        $this->run->markRunning(count($items));

        return $items;
    }

    private function cachedItems(): array
    {
        return Cache::get($this->cacheKey())
            ?? throw new RuntimeException(
                'The ' . $this->run->entity . ' import lost its record list. Start the import again.'
            );
    }

    private function cacheKey(): string
    {
        return 'integration-import-items:' . $this->run->getId();
    }
}
