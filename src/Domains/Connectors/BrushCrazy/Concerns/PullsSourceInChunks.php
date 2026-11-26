<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Kanvas\Connectors\BrushCrazy\Support\ImportPace;
use stdClass;
use Throwable;

trait PullsSourceInChunks
{
    /**
     * `$since` as the UTC wall clock BrushCrazy stores its timestamps in; null on a full pull.
     */
    protected function sourceSince(): ?string
    {
        return $this->since?->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * One failing row is recorded and the walk goes on.
     *
     * @param  callable(array<int, stdClass>): void  $prepareChunk
     * @param  callable(stdClass): mixed  $syncRow
     * @param  array{synced: int, failed: int, errors: array<int, string>}  $results
     */
    protected function pullChunks(
        Builder $query,
        string $keyColumn,
        string $label,
        callable $prepareChunk,
        callable $syncRow,
        array &$results,
        ?callable $onProgress = null
    ): void {
        $query->chunkById(
            $this->chunkSize,
            function (Collection $rows) use ($label, $prepareChunk, $syncRow, &$results, $onProgress) {
                $prepareChunk($rows->all());
                $indexed = [];

                foreach ($rows as $row) {
                    try {
                        $synced = $syncRow($row);
                        $results['synced']++;

                        if ($synced instanceof Model && method_exists($synced, 'searchable')) {
                            $indexed[] = $synced;
                        }
                    } catch (Throwable $e) {
                        $results['failed']++;
                        $results['errors'][] = $label . ':' . $row->id . ' — ' . $e->getMessage();
                    }
                }

                ImportPace::indexChanged($indexed);

                if ($onProgress !== null) {
                    $onProgress($rows->count());
                }

                ImportPace::pauseBetweenChunks();
            },
            $keyColumn,
            'id'
        );
    }
}
