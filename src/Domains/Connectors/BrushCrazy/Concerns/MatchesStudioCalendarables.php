<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Concerns;

use Illuminate\Database\Query\Builder;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;

trait MatchesStudioCalendarables
{
    /**
     * Adds one `OR EXISTS` per calendarable table: a lesson, event or workshop of this studio whose
     * `$column` points at `$outerColumn`, changed since `$since` when one is given.
     */
    protected static function orWhereStudioCalendarable(
        Builder $query,
        string $column,
        string $outerColumn,
        int $bcStudioId,
        ?string $since = null
    ): void {
        foreach (CalendarableTypeEnum::cases() as $morph) {
            $table = $morph->table();

            $query->orWhereExists(
                fn (Builder $calendarables) => $calendarables->selectRaw('1')
                    ->from($table)
                    ->whereColumn($table . '.' . $column, $outerColumn)
                    ->where($table . '.studio_id', $bcStudioId)
                    ->when(
                        $since !== null,
                        fn (Builder $changed) => $changed->where($table . '.updated_at', '>=', $since)
                    )
            );
        }
    }
}
