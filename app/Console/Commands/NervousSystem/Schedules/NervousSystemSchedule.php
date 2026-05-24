<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Schedules;

use App\Console\Commands\Intelligence\CollectAgentSessionTranscriptsCommand;
use App\Console\Commands\NervousSystem\ArchiveOldLedgerEventsCommand;
use App\Console\Commands\NervousSystem\CheckAgentRuntimeHealthCommand;
use App\Console\Commands\NervousSystem\DetectStalledPlanTasksCommand;
use App\Console\Commands\NervousSystem\ExpireCapabilitiesCommand;
use App\Console\Commands\NervousSystem\RecordAgentDailyCyclesCommand;
use App\Console\Commands\NervousSystem\RefreshAgentLiveCountersCommand;
use App\Console\Commands\NervousSystem\SendDailyLearningDigestCommand;
use App\Console\Commands\NervousSystem\SummarizeAgentDailyLearningCommand;
use App\Console\Commands\NervousSystem\SyncModelPricingCommand;
use Illuminate\Console\Scheduling\Schedule;
use Kanvas\NervousSystem\Dashboard\Jobs\RollupDailyDashboardMetricsJob;
use Kanvas\NervousSystem\Pulse\Jobs\RollupDailyPulseMetricsJob;

/**
 * All scheduled work that belongs to the Nervous System domain — agent
 * lifecycle (record/refresh/health), ledger maintenance, pulse + dashboard
 * rollups, plan + capability sweeps, and the daily-learning loop.
 *
 * Co-located with the commands it schedules under `NervousSystem/Schedules/`
 * so the whole domain's console surface lives in one folder. Safe to sit
 * inside the auto-loaded `Commands/` tree because `Kernel::load()` only
 * registers classes extending `Illuminate\Console\Command`; this plain
 * class is skipped.
 *
 * `CollectAgentSessionTranscriptsCommand` lives in the `Intelligence/`
 * commands namespace but is scheduled here — it ingests Hermes transcripts
 * that feed the daily-learning pipeline, so its cadence belongs with the
 * downstream consumers.
 */
final class NervousSystemSchedule
{
    public static function register(Schedule $schedule): void
    {
        // Ledger maintenance — keep the events table from unbounded growth.
        $schedule->command(ArchiveOldLedgerEventsCommand::class)
            ->dailyAt('02:00')
            ->withoutOverlapping();

        // Plan + capability lifecycle.
        $schedule->command(DetectStalledPlanTasksCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command(ExpireCapabilitiesCommand::class)
            ->hourly()
            ->withoutOverlapping();

        // Rollups run early — feed the dashboard + pulse cards before
        // operators log in. Staggered by 5min so they don't slam the DB.
        $schedule->job(new RollupDailyDashboardMetricsJob())
            ->dailyAt('00:30')
            ->withoutOverlapping();
        $schedule->job(new RollupDailyPulseMetricsJob())
            ->dailyAt('00:35')
            ->withoutOverlapping();

        // Daily-cycle pipeline (run order matters — each depends on the prior).
        // 06:04 — deterministic cycle row from yesterday's ledger.
        $schedule->command(RecordAgentDailyCyclesCommand::class)
            ->dailyAt('06:04')
            ->withoutOverlapping();
        // 06:30 — LLM-summarize yesterday's conversations and overwrite the
        // briefing/proposed_actions/durable_facts on the cycle row.
        $schedule->command(SummarizeAgentDailyLearningCommand::class)
            ->dailyAt('06:30')
            ->withoutOverlapping()
            ->onOneServer();
        // 07:30 — fan out the per-company digest email after the summarize
        // queue has drained.
        $schedule->command(SendDailyLearningDigestCommand::class)
            ->dailyAt('07:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Agent runtime monitoring.
        $schedule->command(RefreshAgentLiveCountersCommand::class)
            ->hourly()
            ->withoutOverlapping();
        $schedule->command(CheckAgentRuntimeHealthCommand::class)
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Hermes transcript ingestion (Intelligence-namespaced, but its
        // output is the input for the 06:30 summarize step above).
        $schedule->command(CollectAgentSessionTranscriptsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Model pricing sync — daily, before the rollups would surface any
        // pricing-derived cost numbers.
        $schedule->command(SyncModelPricingCommand::class)
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
