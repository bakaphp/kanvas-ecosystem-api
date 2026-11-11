<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use App\Console\Commands\Connectors\BrushCrazy\ImportFromBrushCrazyCommand;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ImportCursorTest extends TestCase
{
    public function testCleanFullOrIncrementalPassAdvancesToTheRunStart(): void
    {
        $runStartedAt = Carbon::parse('2026-11-01 10:00:00', 'UTC');

        $cursor = ImportFromBrushCrazyCommand::nextCursor(
            false,
            0,
            $runStartedAt,
            null
        );

        $this->assertTrue($cursor->equalTo($runStartedAt));
    }

    /**
     * Regression: a --since repair window advanced the cursor to now, so the next --incremental
     * run skipped every change between the old cursor and the --since start.
     */
    public function testExplicitSinceNeverMovesTheCursor(): void
    {
        $this->assertNull(ImportFromBrushCrazyCommand::nextCursor(
            true,
            0,
            Carbon::parse('2026-11-01 10:00:00', 'UTC'),
            null
        ));
    }

    public function testAFailedRowHoldsTheCursor(): void
    {
        $this->assertNull(ImportFromBrushCrazyCommand::nextCursor(
            false,
            1,
            Carbon::parse('2026-11-01 10:00:00', 'UTC'),
            null
        ));
    }

    public function testTheCursorNeverMovesPastCutover(): void
    {
        $cutoverAt = Carbon::parse('2026-10-15 00:00:00', 'UTC');

        $cursor = ImportFromBrushCrazyCommand::nextCursor(
            false,
            0,
            Carbon::parse('2026-11-01 10:00:00', 'UTC'),
            $cutoverAt
        );

        $this->assertTrue($cursor->equalTo($cutoverAt));
    }
}
