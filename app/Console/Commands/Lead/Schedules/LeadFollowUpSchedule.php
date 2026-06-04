<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead\Schedules;

use App\Console\Commands\Lead\DispatchLeadFollowUpsCommand;
use App\Console\Commands\Lead\LeadFollowUpDailySummaryCommand;
use Illuminate\Console\Scheduling\Schedule;

final class LeadFollowUpSchedule
{
    /**
     * Timing map at a glance. The hourly dispatch is UTC-anchored because
     * `DispatchLeadFollowUpsCommand` fans out per-tenant and re-checks each
     * company's work hours via `CompanyWorkHoursTool` inside the per-app
     * iteration — UTC is just the cron clock, not a tenant decision.
     *
     * ── Hourly (UTC-anchored, per-tenant work-hours-gated downstream) ──
     *   :00  DispatchLeadFollowUps         (per-tenant candidate scan + fan-out)
     *
     * ── Daily (UTC-anchored) ────────────────────────────────────────────
     *   00:30 UTC  LeadFollowUpDailySummary (per-tenant rollup ledger event)
     *
     * Why 00:30 UTC for the daily? The action computes "yesterday" per
     * tenant timezone internally; a UTC fire at 00:30 still aggregates
     * the correct local-day data because by then yesterday is fully
     * elapsed in every US timezone and most APAC. If we ever onboard
     * a tenant west of UTC-9 we'll need to revisit.
     */
    public static function register(Schedule $schedule): void
    {
        // Hourly fan-out — `withoutOverlapping(10)` caps the lock to 10 min
        // so a stuck run can't block the next hour entirely.
        $schedule->command(DispatchLeadFollowUpsCommand::class)
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground();

        // Daily summary — fires once per (app, company) into the ledger.
        $schedule->command(LeadFollowUpDailySummaryCommand::class)
            ->dailyAt('00:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }
}
