<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Contracts;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;

/**
 * A definition that knows how to produce its own rows.
 *
 * Split from ReportDefinitionInterface so the schema layer stays usable on its own — a table can
 * exist and be queried before anything refreshes it.
 *
 * The refresh service owns the mechanics (chunking, upsert, `refreshed_at`, reconciliation); the
 * definition owns the source knowledge. That is the same domain-holds-the-rules split as the rest
 * of this layer.
 */
interface RefreshableReportInterface extends ReportDefinitionInterface
{
    /**
     * Produce flat rows, keyed by column name.
     *
     * Yielded rather than returned so a full rebuild of 65k people does not materialise in
     * memory. The primary key and `companies_id` must be present in each row; `refreshed_at` is
     * stamped by the service.
     *
     * @param array<int, int>|null $ids restrict to these primary-key values; null rebuilds all
     *
     * @return iterable<array<string, mixed>>
     */
    public function rowsFor(AppInterface $app, Companies $company, ?array $ids = null): iterable;

    /**
     * Reverse dependencies: which source models invalidate which rows.
     *
     * The dangerous case is fan-out. A person's row depends on data that is not the person — an
     * Organization rename touches every one of its people, an EventVersion date change touches
     * every registration for it. Each entry maps a source model class to a callable that, given
     * one of those models, returns the primary-key values whose rows are now stale.
     *
     * These must be resolved in a queued batch, never synchronously on the save: renaming a
     * company with 500 people cannot block the request that renamed it.
     *
     * @return array<class-string, callable(object): array<int, int>>
     */
    public function invalidatedBy(): array;
}
