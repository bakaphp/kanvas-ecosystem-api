<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\BrushCrazy\Schedules;

use App\Console\Commands\Connectors\BrushCrazy\DispatchBrushCrazyIncrementalImportCommand;
use Illuminate\Console\Scheduling\Schedule;

final class BrushCrazySchedule
{
    public static function register(Schedule $schedule): void
    {
        // A full incremental pass can outrun 15 minutes after a large BrushCrazy backlog; the lock
        // skips the overlapping tick and expires on its own so a killed run can't stall the net.
        $schedule->command(DispatchBrushCrazyIncrementalImportCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping(120)
            ->onOneServer()
            ->runInBackground();
    }
}
