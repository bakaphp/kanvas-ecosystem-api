<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportStatusEnum;

/**
 * The last import of one entity for one company, kept as a company setting: the run is written by
 * one job at a time (each chunk queues the next), so there is never a concurrent writer to race.
 * The id list is too large for a setting and only matters while the run lasts, so it lives in the cache.
 */
class NetSuiteImportStatusService
{
    /**
     * A run that has not reported for this long died without reaching failed() — a killed worker —
     * and must not block the next import forever. Well above a chunk's worst case.
     */
    public const int STALE_AFTER_HOURS = 3;

    public const int MAX_ERRORS = 50;

    public function __construct(
        protected Companies $company,
        protected ImportEntityEnum $entity
    ) {
    }

    public function current(): ?array
    {
        $status = $this->company->get($this->entity->statusKey());

        return is_array($status) ? $status : null;
    }

    public function isInProgress(): bool
    {
        $current = $this->current();

        if ($current === null || ! ImportStatusEnum::from($current['status'])->isInProgress()) {
            return false;
        }

        return Carbon::parse($current['updated_at'])->greaterThan(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * @param list<string> $locationIds
     */
    public function queue(?string $savedSearchId, array $locationIds = []): array
    {
        $this->forgetIds();

        return $this->write([
            'entity' => $this->entity->value,
            'status' => ImportStatusEnum::QUEUED->value,
            'saved_search_id' => $savedSearchId,
            'location_ids' => $locationIds,
            'total' => null,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'summary' => null,
            'error' => null,
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function start(?int $total): void
    {
        $this->update([
            'status' => ImportStatusEnum::RUNNING->value,
            'total' => $total,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array{created: int, updated: int, skipped: int, failed: int, errors: list<array>} $chunk
     */
    public function advance(array $chunk): void
    {
        $current = $this->current() ?? [];

        $this->update([
            'processed' => ($current['processed'] ?? 0) + $chunk['created'] + $chunk['updated'] + $chunk['skipped'] + $chunk['failed'],
            'created' => ($current['created'] ?? 0) + $chunk['created'],
            'updated' => ($current['updated'] ?? 0) + $chunk['updated'],
            'skipped' => ($current['skipped'] ?? 0) + $chunk['skipped'],
            'failed' => ($current['failed'] ?? 0) + $chunk['failed'],
            'errors' => array_slice([...($current['errors'] ?? []), ...$chunk['errors']], 0, self::MAX_ERRORS),
        ]);
    }

    public function complete(?array $summary = null): void
    {
        $this->forgetIds();
        $this->update([
            'status' => ImportStatusEnum::COMPLETED->value,
            'summary' => $summary,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    public function fail(string $error): void
    {
        $this->forgetIds();
        $this->update([
            'status' => ImportStatusEnum::FAILED->value,
            'error' => $error,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param list<string> $ids
     */
    public function rememberIds(array $ids): void
    {
        Cache::put($this->idsKey(), $ids, now()->addHours(self::STALE_AFTER_HOURS * 8));
    }

    /**
     * @return list<string>|null
     */
    public function ids(): ?array
    {
        $ids = Cache::get($this->idsKey());

        return is_array($ids) ? $ids : null;
    }

    private function forgetIds(): void
    {
        Cache::forget($this->idsKey());
    }

    private function update(array $changes): void
    {
        $this->write([...($this->current() ?? []), ...$changes]);
    }

    private function write(array $status): array
    {
        $status['updated_at'] = now()->toIso8601String();
        $this->company->set($this->entity->statusKey(), $status);

        return $status;
    }

    private function idsKey(): string
    {
        return 'netsuite-import-ids:' . $this->company->getId() . ':' . $this->entity->value;
    }
}
