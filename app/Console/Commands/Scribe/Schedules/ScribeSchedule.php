<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe\Schedules;

use App\Console\Commands\Scribe\EvaluateInvoiceAgingCommand;
use App\Console\Commands\Scribe\SendReimbursementDigestCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Scribe domain cron registry. Wired into Kernel.php::schedule().
 *
 * Timing notes (America/New_York anchor — financial domain runs on US business hours):
 *   01:15 NY  EvaluateInvoiceAgingCommand
 *             — well after midnight so freshly-issued invoices "yesterday" count;
 *             — ahead of NervousSystem Dashboard rollup (00:30) is fine since dashboard
 *               doesn't depend on Scribe aging state today.
 *   1st 08:00 NY  SendReimbursementDigestCommand
 *             — monthly, on the 1st, so it reports a month that has actually closed;
 *             — business hours because it is read by employees, not by a system.
 */
final class ScribeSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(EvaluateInvoiceAgingCommand::class)
            ->dailyAt('01:15')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(SendReimbursementDigestCommand::class)
            ->monthlyOn(1, '08:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
