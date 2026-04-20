<?php

declare(strict_types=1);

namespace Kanvas\Analytics\DataTransferObject;

use Closure;

class AnalyticsGroupBy
{
    /**
     * @param  string  $column  Column on the source table to group by (e.g. "leads_status_id", "status").
     * @param  string|null  $relation  Optional Eloquent relation name to resolve a human label from (e.g. "status").
     * @param  string|null  $labelColumn  Column on the related table to use as the `name` label (e.g. "name", "displayname").
     * @param  Closure|null  $labelResolver  Optional callable(mixed $key): string for custom label resolution.
     *                                       Receives the raw key value, must return the display string.
     * @param  string|null  $sumColumn  Optional column to sum per category (mirrors the top-level sumColumn).
     */
    public function __construct(
        public readonly string $column,
        public readonly ?string $relation = null,
        public readonly ?string $labelColumn = null,
        public readonly ?Closure $labelResolver = null,
        public readonly ?string $sumColumn = null,
    ) {
    }
}
