<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics\Schedules;

use App\Console\Commands\Analytics\ReportingRebuildCommand;
use App\Console\Commands\Analytics\ReportingReconcileCommand;
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
 *
 *   Daily 03:00 UTC  ReportingRebuildCommand
 *     — The reconciliation pass for the flat reporting tables. Incremental refresh cannot tell
 *       "deleted at source" from "not in this batch", so a full rebuild is the only run that
 *       prunes. 03:00 keeps it clear of the business day in every tenant timezone.
 *     — runInBackground() because a full rebuild of a large agency is minutes, not seconds, and
 *       must not hold the scheduler.
 *
 *   Daily 05:00 UTC  ReportingReconcileCommand
 *     — Two hours after the rebuild, so it reports on a table that just finished rather than one
 *       mid-write. A flat table fails by being quietly wrong rather than by raising, so drift is
 *       only visible if something looks for it.
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

        // App 49 (INTRAS) is the only tenant with reporting definitions today. When the registry
        // moves off its hardcoded map and onto the `integrations` row, this becomes a loop over
        // the apps that have the connector enabled.
        $schedule->command(ReportingRebuildCommand::class, ['49'])
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        $schedule->command(ReportingReconcileCommand::class, ['49'])
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }
}
