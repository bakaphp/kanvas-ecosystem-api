<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Schedules;

use App\Console\Commands\Intelligence\Agents\DailyAgentConfigBackupCommand;
use App\Console\Commands\Intelligence\Usage\CollectAgentDeploymentUsageCommand;
use App\Console\Commands\Intelligence\Usage\CollectAgentSessionTranscriptsCommand;
use App\Console\Commands\Intelligence\Usage\RollupLocalAgentUsageCommand;
use App\Console\Commands\NervousSystem\Agents\CheckAgentRuntimeHealthCommand;
use App\Console\Commands\NervousSystem\Agents\ExpireCapabilitiesCommand;
use App\Console\Commands\NervousSystem\Agents\RefreshAgentLiveCountersCommand;
use App\Console\Commands\NervousSystem\Learning\RecordAgentDailyCyclesCommand;
use App\Console\Commands\NervousSystem\Learning\SendDailyLearningDigestCommand;
use App\Console\Commands\NervousSystem\Learning\SummarizeAgentDailyLearningCommand;
use App\Console\Commands\NervousSystem\Ledger\ArchiveOldLedgerEventsCommand;
use App\Console\Commands\NervousSystem\Metrics\SyncModelPricingCommand;
use App\Console\Commands\NervousSystem\Plans\DetectStalledPlanTasksCommand;
use App\Console\Commands\NervousSystem\Plans\NudgeInactivePlansCommand;
use App\Console\Commands\NervousSystem\Plans\ProjectHeartbeatCommand;
use App\Console\Commands\NervousSystem\Plans\SweepStaleIntakeCommand;
use App\Console\Commands\NervousSystem\Plans\SyncKanbanDeploymentsCommand;
use App\Console\Commands\NervousSystem\Scheduling\SweepScheduledActionsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Kanvas\NervousSystem\Dashboard\Jobs\RollupDailyDashboardMetricsJob;
use Kanvas\NervousSystem\Pulse\Jobs\RollupDailyPulseMetricsJob;

final class NervousSystemSchedule
{
    /**
     * Timing map at a glance. All `dailyAt(...)` slots run in
     * `America/New_York` so the daily-learning pipeline fires after every US
     * tenant's "yesterday" is fully elapsed (Laravel's ->timezone() modifier
     * handles DST). Interval-based slots (`everyN`, `hourlyAt`) stay
     * UTC-anchored. All slots `withoutOverlapping()`.
     *
     * ── Sub-hourly (interval-based, TZ-irrelevant) ────────────────────
     *   every 5m   DetectStalledPlanTasks    (idempotent ledger sweep)
     *   every 10m  CheckAgentRuntimeHealth   (per-deployment SSH ping)
     *
     * ── Hourly, staggered to avoid :00 thundering herd ────────────────
     *   :00     ExpireCapabilities           (cheap UPDATE sweep)
     *   :05     RefreshAgentLiveCounters     (full-fleet DB scan)
     *   :10     CollectAgentSessionTranscripts (SSH ingest, runs in bg)
     *
     * ── Daily (America/New_York) ──────────────────────────────────────
     *   00:30 NY   RollupDailyDashboardMetrics
     *   00:35 NY   RollupDailyPulseMetrics      (+5min after dashboard)
     *   02:00 NY   ArchiveOldLedgerEvents
     *   02:30 NY   SyncModelPricing
     *   06:04 NY   RecordAgentDailyCycles       ← daily-learning pipeline
     *   06:30 NY   SummarizeAgentDailyLearning  ← 26min buffer for record
     *   07:30 NY   SendDailyLearningDigest      ← 60min buffer for queue
     *   08:00 NY   NudgeInactivePlans
     *   08:15 NY   SweepStaleIntake             (+15min after the nudge)
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
            ->timezone('America/New_York')
            ->withoutOverlapping();

        // Plan + capability lifecycle. ExpireCapabilities stays at :00 —
        // it's a cheap UPDATE that's unlikely to contend with the every-5/10
        // min checks that also fire there.
        $schedule->command(DetectStalledPlanTasksCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Project heartbeat — the proactive pulse. Runs every 5 min; each project only ticks when
        // its own heartbeat_interval_minutes has elapsed.
        $schedule->command(ProjectHeartbeatCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command(ExpireCapabilitiesCommand::class)
            ->hourly()
            ->withoutOverlapping();

        // Scheduled agent actions (reminders / agent tasks) — the every-minute sweep claims the due
        // batch and dispatches a fire-job per row. Pure dispatcher; ±1 min fire precision.
        $schedule->command(SweepScheduledActionsCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        // Inactive-plan nudge — once a day, ping owners of open plans that have gone silent past the
        // 24h threshold. Daily (not the 5-min heartbeat cadence) because the signal is day-scale and the
        // action posts a comment / notifies a human; the action's own ledger guard prevents re-nudging.
        $schedule->command(NudgeInactivePlansCommand::class)
            ->dailyAt('08:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        // Unanswered intake — chase the owner, and after three unanswered rounds cancel the plan.
        // A plan parked in INTAKE looks like work in progress, so leaving it there is worse than
        // never having created it. Staggered 15min after the inactive-plan nudge so an owner with
        // both a silent plan and a silent intake gets two separate pings rather than one pile.
        // Daily rather than hourly because the action's own 24h window guard means a finer cadence
        // would re-scan without ever chasing sooner.
        $schedule->command(SweepStaleIntakeCommand::class)
            ->dailyAt('08:15')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        // Daily rollups feed dashboard + pulse cards before operators log in.
        // Staggered by 5min so they don't slam the DB simultaneously.
        $schedule->job(new RollupDailyDashboardMetricsJob())
            ->dailyAt('00:30')
            ->timezone('America/New_York')
            ->withoutOverlapping();
        $schedule->job(new RollupDailyPulseMetricsJob())
            ->dailyAt('00:35')
            ->timezone('America/New_York')
            ->withoutOverlapping();

        // Daily-learning pipeline — strict order, see timing map above.
        $schedule->command(RecordAgentDailyCyclesCommand::class)
            ->dailyAt('06:04')
            ->timezone('America/New_York')
            ->withoutOverlapping();
        $schedule->command(SummarizeAgentDailyLearningCommand::class)
            ->dailyAt('06:30')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command(SendDailyLearningDigestCommand::class)
            ->dailyAt('07:30')
            ->timezone('America/New_York')
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

        // Hermes kanban ingest — fan out a sync job per running Hermes deployment so the
        // agent's board moves into Kanvas plans/tasks. Status-diff per task, so over-running
        // is harmless; the per-deployment jobs run on the agent-runtime queue.
        $schedule->command(SyncKanbanDeploymentsCommand::class)
            ->everyFiveMinutes()
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
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        // Container-runtime usage collection (OpenClaw + Hermes) — provider-routed,
        // SSH-heavy like transcripts, so staggered to :15 (after the :10 transcript
        // ingest) and run in background.
        $schedule->command(CollectAgentDeploymentUsageCommand::class)
            ->hourlyAt(15)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // In-process backends (Neuron/Laravel) usage rollup — defaults to yesterday.
        // Runs at 03:00 NY: after SyncModelPricing (02:30) so cost uses fresh rates,
        // before RecordAgentDailyCycles (06:04) consumes the day's usage.
        $schedule->command(RollupLocalAgentUsageCommand::class)
            ->dailyAt('03:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        // End-of-day config backup — runs hourly and dispatches only for agents
        // whose company's local time is 23:xx, so each timezone gets its own EOD backup.
        /*  $schedule->command(DailyAgentConfigBackupCommand::class)
             ->hourly()
             ->withoutOverlapping()
             ->onOneServer(); */
    }
}
