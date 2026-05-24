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
    /**
     * Timing map at a glance (UTC, all `withoutOverlapping()` so a slow run
     * skips the next slot rather than stacking):
     *
     * ── Sub-hourly ─────────────────────────────────────────────────────
     *   every 5m   DetectStalledPlanTasks    (idempotent ledger sweep)
     *   every 10m  CheckAgentRuntimeHealth   (per-deployment SSH ping)
     *
     * ── Hourly, staggered to avoid :00 thundering herd ────────────────
     *   :00     ExpireCapabilities           (cheap UPDATE sweep)
     *   :05     RefreshAgentLiveCounters     (full-fleet DB scan)
     *   :10     CollectAgentSessionTranscripts (SSH ingest, runs in bg)
     *
     * ── Daily ─────────────────────────────────────────────────────────
     *   00:30   RollupDailyDashboardMetrics
     *   00:35   RollupDailyPulseMetrics      (+5min after dashboard)
     *   02:00   ArchiveOldLedgerEvents
     *   02:30   SyncModelPricing
     *   06:04   RecordAgentDailyCycles       ← daily-learning pipeline
     *   06:30   SummarizeAgentDailyLearning  ← 26min buffer for record
     *   07:30   SendDailyLearningDigest      ← 60min buffer for queue
     *
     * Daily-learning pipeline buffers depend on:
     *  - RecordAgentDailyCycles finishing in <26min for all active agents
     *    (deterministic ledger rollup, scales with agents × yesterday's
     *    event volume; well within budget today).
     *  - The agent-runtime queue draining within 60min after 06:30. The
     *    queue has multi-replica workers in dev + prod compose; budget
     *    holds at ~100 agents × 30s LLM round-trip = ~50min serial-worst.
     */
    public static function register(Schedule $schedule): void
    {
        // Ledger maintenance — keep the events table from unbounded growth.
        $schedule->command(ArchiveOldLedgerEventsCommand::class)
            ->dailyAt('02:00')
            ->withoutOverlapping();

        // Plan + capability lifecycle. ExpireCapabilities stays at :00 —
        // it's a cheap UPDATE that's unlikely to contend with the every-5/10
        // min checks that also fire there.
        $schedule->command(DetectStalledPlanTasksCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command(ExpireCapabilitiesCommand::class)
            ->hourly()
            ->withoutOverlapping();

        // Daily rollups feed dashboard + pulse cards before operators log in.
        // Staggered by 5min so they don't slam the DB simultaneously.
        $schedule->job(new RollupDailyDashboardMetricsJob())
            ->dailyAt('00:30')
            ->withoutOverlapping();
        $schedule->job(new RollupDailyPulseMetricsJob())
            ->dailyAt('00:35')
            ->withoutOverlapping();

        // Daily-learning pipeline — strict order, see timing map above.
        $schedule->command(RecordAgentDailyCyclesCommand::class)
            ->dailyAt('06:04')
            ->withoutOverlapping();
        $schedule->command(SummarizeAgentDailyLearningCommand::class)
            ->dailyAt('06:30')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command(SendDailyLearningDigestCommand::class)
            ->dailyAt('07:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Agent runtime monitoring. RefreshAgentLiveCounters does a full-fleet
        // DB scan — kept off :00 to dodge the every-5/every-10/hourly cluster.
        $schedule->command(RefreshAgentLiveCountersCommand::class)
            ->hourlyAt(5)
            ->withoutOverlapping();
        $schedule->command(CheckAgentRuntimeHealthCommand::class)
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Hermes transcript ingestion — Intelligence-namespaced, but feeds
        // the 06:30 summarize step. Staggered to :10 because SSH-ingest is
        // the heaviest hourly job; `runInBackground` so the scheduler doesn't
        // block on the actual ingest dispatch.
        $schedule->command(CollectAgentSessionTranscriptsCommand::class)
            ->hourlyAt(10)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Model pricing sync — daily, before any rollup that derives cost
        // figures from current pricing.
        $schedule->command(SyncModelPricingCommand::class)
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
