<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe\Schedules;

use App\Console\Commands\Scribe\EvaluateInvoiceAgingCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Scribe domain cron registry. Wired into Kernel.php::schedule().
 *
 * Timing notes (America/New_York anchor — financial domain runs on US business hours):
 *   01:15 NY  EvaluateInvoiceAgingCommand
 *             — well after midnight so freshly-issued invoices "yesterday" count;
 *             — ahead of NervousSystem Dashboard rollup (00:30) is fine since dashboard
 *               doesn't depend on Scribe aging state today.
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
    }
}
