<?php

declare(strict_types=1);

namespace App\Console\Commands\CustomerSuccess\Schedules;

use App\Console\Commands\CustomerSuccess\DraftMonthlyCustomerUpdatesCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Customer Success domain cron registry. Wired into Kernel.php::schedule().
 *
 * Timing notes (America/New_York anchor — this produces work for a human, so it lands on their clock):
 *   1st of the month, 08:00 NY  DraftMonthlyCustomerUpdatesCommand
 *     — the 1st, because the draft covers a rolling 30-day window and the whole previous month is
 *       then inside it whichever day the run lands on;
 *     — 08:00 so the cards are waiting when the CSM opens their feed, rather than arriving mid-morning
 *       on top of whatever they are already doing;
 *     — no --app_id, so it discovers every app with a subscribed account. A newly tagged account on a
 *       new app is picked up on the next run without editing this file.
 *
 * It posts approval cards and mails nobody, so an over-eager month costs a human deleting cards rather
 * than customers receiving something. That is what makes it safe to schedule at all.
 */
final class CustomerSuccessSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(DraftMonthlyCustomerUpdatesCommand::class)
            ->monthlyOn(1, '08:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
