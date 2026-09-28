<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Ledger;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * One-shot backfill for the `category` column on nervous_system_events.
 *
 * Idempotent — chunks over rows where category IS NULL, computes via
 * Event::categoryFor(), writes the column. Safe to re-run; safe to
 * cancel mid-run. Internal events (no mapping) get category=null and
 * are skipped on subsequent passes via the NULL filter.
 */
class BackfillLedgerEventCategoriesCommand extends Command
{
    protected $signature = 'kanvas:backfill-ledger-event-categories
        {--chunk=2000 : Rows per pass}
        {--limit= : Optional cap on total rows touched (for staging dry-runs)}';

    protected $description = 'Populate nervous_system_events.category for legacy rows.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $touched = 0;
        $mapped = 0;
        $unmapped = 0;

        $this->info(sprintf('Backfilling category — chunk=%d%s', $chunk, $limit !== null ? " limit={$limit}" : ''));

        $cursorId = 0;

        while (true) {
            $rows = DB::connection('intelligence')
                ->table('nervous_system_events')
                ->whereNull('category')
                ->where('id', '>', $cursorId)
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'event_type', 'status']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $category = Event::categoryFor((string) $row->event_type, (string) $row->status);

                // Mark the row even when category is null so the next
                // pass doesn't re-evaluate it. Use a sentinel by writing
                // the actual null — but our NULL filter would re-pick it
                // up. Instead, only update when we have a value, and
                // track unmapped count separately.
                if ($category !== null) {
                    DB::connection('intelligence')
                        ->table('nervous_system_events')
                        ->where('id', $row->id)
                        ->update(['category' => $category->value]);
                    $mapped++;
                } else {
                    $unmapped++;
                }

                $cursorId = $row->id;
                $touched++;

                if ($limit !== null && $touched >= $limit) {
                    break 2;
                }
            }

            $this->line("  …processed up to id={$cursorId} (touched={$touched}, mapped={$mapped}, unmapped={$unmapped})");
        }

        $this->info(sprintf(
            'Done. Touched: %d · Mapped: %d · Unmapped (internal events): %d',
            $touched,
            $mapped,
            $unmapped,
        ));

        return self::SUCCESS;
    }
}
