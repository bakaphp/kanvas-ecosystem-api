<?php

namespace App\Console;

use App\Console\Commands\Connectors\Movipass\ChargeLateOrdersCommand;
use App\Console\Commands\Connectors\Movipass\CheckExpiringOrdersCommand;
use App\Console\Commands\Connectors\Notifications\MailCaddieLabCommand;
use App\Console\Commands\Connectors\OpenClaw\CollectAgentTelemetryCommand;
use App\Console\Commands\Ecosystem\Users\DeleteUsersRequestedCommand;
use App\Console\Commands\ImportPromptsFromDocsCommand;
use App\Console\Commands\Intelligence\CollectAgentSessionTranscriptsCommand;
use App\Console\Commands\NervousSystem\ArchiveOldLedgerEventsCommand;
use App\Console\Commands\NervousSystem\CheckAgentRuntimeHealthCommand;
use App\Console\Commands\NervousSystem\DetectStalledPlanTasksCommand;
use App\Console\Commands\NervousSystem\ExpireCapabilitiesCommand;
use App\Console\Commands\NervousSystem\RecordAgentDailyCyclesCommand;
use App\Console\Commands\NervousSystem\RefreshAgentLiveCountersCommand;
use App\Console\Commands\NervousSystem\SyncModelPricingCommand;
use App\Console\Commands\Social\ScoutMessageReindexCommand;
use App\Console\Commands\Social\SocialUserCounterResetCommand;
use App\Console\Commands\Souk\CancelStalePaymentsCommand;
use App\Console\Commands\Souk\OrderFinishExpiredCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Kanvas\NervousSystem\Dashboard\Jobs\RollupDailyDashboardMetricsJob;
use Kanvas\NervousSystem\Pulse\Jobs\RollupDailyPulseMetricsJob;
use Override;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    #[Override]
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(RunHealthChecksCommand::class)->everyMinute();
        $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
        #$schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
        $schedule->command(DeleteUsersRequestedCommand::class)->dailyAt('00:00');
        $schedule->command(SocialUserCounterResetCommand::class, ['13'])->dailyAt('00:00');
        $schedule->command(OrderFinishExpiredCommand::class)->everyMinute();
        $schedule->command(CheckExpiringOrdersCommand::class)->everyMinute();
        $schedule->command(ChargeLateOrdersCommand::class)->hourly();
        $schedule->command(CancelStalePaymentsCommand::class)->everyFiveMinutes();
        $schedule->command(ArchiveOldLedgerEventsCommand::class)
            ->dailyAt('02:00')
            ->withoutOverlapping();
        $schedule->command(DetectStalledPlanTasksCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command(ExpireCapabilitiesCommand::class)
            ->hourly()
            ->withoutOverlapping();
        $schedule->job(new RollupDailyDashboardMetricsJob())
            ->dailyAt('00:30')
            ->withoutOverlapping();
        $schedule->job(new RollupDailyPulseMetricsJob())
            ->dailyAt('00:35')
            ->withoutOverlapping();
        $schedule->command(RecordAgentDailyCyclesCommand::class)
            ->dailyAt('06:04')
            ->withoutOverlapping();
        $schedule->command(RefreshAgentLiveCountersCommand::class)
            ->hourly()
            ->withoutOverlapping();
        $schedule->command(CheckAgentRuntimeHealthCommand::class)
            ->everyTenMinutes()
            ->withoutOverlapping();
        $schedule->command(CollectAgentSessionTranscriptsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
        $schedule->command(SyncModelPricingCommand::class)
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->onOneServer();
        /*         $schedule->command(CollectAgentTelemetryCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->runInBackground(); */
        //$schedule->command(SendBookingRemindersCommand::class)->everyFiveMinutes();
        #$schedule->command(ScoutMessageReindexCommand::class, [env('MESSAGE_REINDEX_SCOUT_APP_ID', '13'), env('MESSAGE_REINDEX_SCOUT_MESSAGE_TYPES_ID', '572')])->everyTenMinutes();
        #$schedule->command(MailunregisteredUsersCampaignCommand::class)->weeklyOn(2, '2:30'); //@todo move this to normal cron
        #$schedule->command(ImportPromptsFromDocsCommand::class)->weeklyOn(1, '00:00');

        /**
         * @todo move this to a cron subSystem
         */
        /*        if (getenv('CADDIE_APP_KEY')) {
                   $schedule->command(MailCaddieLabCommand::class, [getenv('CADDIE_APP_KEY')])
                       ->dailyAt('13:00')
                       ->timezone('America/New_York');
               } */
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    #[Override]
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
