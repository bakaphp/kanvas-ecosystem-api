<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Observers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Analytics\Reporting\Concerns\RefreshesReportRows;

/**
 * Keeps flat report rows in step with the entities they are built from.
 *
 * Attach to any model a definition names in `invalidatedBy()`:
 *
 *   #[ObservedBy([ReportSourceObserver::class])]
 *
 * `saved()` rather than `updating()`: clearing before the write leaves a window where a
 * concurrent read re-populates the row from uncommitted data. It also covers inserts, so one
 * hook replaces two.
 */
class ReportSourceObserver
{
    use RefreshesReportRows;

    public function saved(Model $model): void
    {
        $this->dispatchReportRefresh($model);
    }

    public function deleted(Model $model): void
    {
        // A soft delete still needs the row rewritten — the flat table carries the flag rather
        // than dropping the row, so historical counts keep reconciling.
        $this->dispatchReportRefresh($model);
    }
}
