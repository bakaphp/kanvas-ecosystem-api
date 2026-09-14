<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics\Schedules;

use App\Console\Commands\Analytics\SendEngageUsageReportCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Analytics domain cron registry. Wired into Kernel.php::schedule().
 *
 * Timing notes:
 *   Mon 08:00 America/New_York  SendEngageUsageReportCommand
 *     — Monday morning so the report lands on the week's first working day covering the seven
 *       complete days behind it. The command resolves that window per company timezone, so the
 *       NY anchor is only the cron clock, not a tenant decision.
 *     — onOneServer() because the fan-out mails managers; a second worker firing it would
 *       double-send.
 */
final class AnalyticsSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(SendEngageUsageReportCommand::class)
            ->weeklyOn(1, '08:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }
}
