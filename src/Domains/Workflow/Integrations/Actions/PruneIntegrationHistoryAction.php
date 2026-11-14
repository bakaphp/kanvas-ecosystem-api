<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes integration history written before a cutoff. The table has no index on `created_at` and
 * holds tens of millions of rows, so the cutoff is turned into an id by halving on the primary key
 * (ids grow with time) and rows go in small batches by primary key. It stops at `$deadline`: a
 * backlog is cleared over several runs instead of one that holds the table for hours.
 */
final class PruneIntegrationHistoryAction
{
    public const string TABLE = 'entity_integration_history';

    public function __construct(
        private readonly Carbon $cutoff,
        private readonly Carbon $deadline,
        private readonly ?int $onlyStatusId = null,
        private readonly int $batch = 5000,
        private readonly string $table = self::TABLE
    ) {
    }

    /**
     * @return int rows deleted
     */
    public function execute(): int
    {
        $cutoffId = $this->firstIdOnOrAfter();

        if ($cutoffId === null) {
            return 0;
        }

        $deleted = 0;

        do {
            $removed = $this->query()
                ->where('id', '<', $cutoffId)
                ->when($this->onlyStatusId !== null, fn (Builder $query) => $query->where('status_id', $this->onlyStatusId))
                ->orderBy('id')
                ->limit($this->batch)
                ->delete();
            $deleted += $removed;
        } while ($removed === $this->batch && now()->lt($this->deadline));

        return $deleted;
    }

    /**
     * Null when nothing is old enough. When every row is, the id after the last one.
     */
    private function firstIdOnOrAfter(): ?int
    {
        $low = (int) $this->query()->min('id');
        $high = (int) $this->query()->max('id');

        if ($high === 0 || $this->createdAtFrom($low)?->gte($this->cutoff) !== false) {
            return null;
        }

        if ($this->createdAtFrom($high)?->lt($this->cutoff) === true) {
            return $high + 1;
        }

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->createdAtFrom($middle)?->gte($this->cutoff) !== false) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }

    private function createdAtFrom(int $id): ?Carbon
    {
        $createdAt = $this->query()->where('id', '>=', $id)->orderBy('id')->value('created_at');

        return $createdAt !== null ? Carbon::parse($createdAt) : null;
    }

    private function query(): Builder
    {
        return DB::connection('workflow')->table($this->table);
    }
}
