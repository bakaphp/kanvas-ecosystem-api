<?php

namespace App\Console;

use App\Console\Commands\Connectors\Movipass\ChargeLateOrdersCommand;
use App\Console\Commands\Connectors\Movipass\CheckExpiringOrdersCommand;
use App\Console\Commands\Connectors\Notifications\MailCaddieLabCommand;
use App\Console\Commands\Connectors\OpenClaw\CollectAgentTelemetryCommand;
use App\Console\Commands\Ecosystem\Users\DeleteUsersRequestedCommand;
use App\Console\Commands\ImportPromptsFromDocsCommand;
use App\Console\Commands\Lead\Schedules\LeadFollowUpSchedule;
use App\Console\Commands\NervousSystem\Schedules\NervousSystemSchedule;
use App\Console\Commands\Scribe\Schedules\ScribeSchedule;
use App\Console\Commands\Social\ScoutMessageReindexCommand;
use App\Console\Commands\Social\SocialUserCounterResetCommand;
use App\Console\Commands\Souk\CancelStalePaymentsCommand;
use App\Console\Commands\Souk\OrderFinishExpiredCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
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
    // Domain-grouped schedules live in App\Console\Schedules\* — extract the
    // next domain into its own class as soon as its entries hit 3+ here, so
    // this method stays a thin dispatcher rather than a god-list.
    #[Override]
    protected function schedule(Schedule $schedule)
    {
        // Platform health (Spatie).
        $schedule->command(RunHealthChecksCommand::class)->everyMinute();
        $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
        #$schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();

        // Ecosystem / Social / Souk / Connectors — small enough to inline today.
        $schedule->command(DeleteUsersRequestedCommand::class)->dailyAt('00:00');
        $schedule->command(SocialUserCounterResetCommand::class, ['13'])->dailyAt('00:00');
        $schedule->command(OrderFinishExpiredCommand::class)->everyMinute();
        $schedule->command(CheckExpiringOrdersCommand::class)->everyMinute();
        $schedule->command(ChargeLateOrdersCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(CancelStalePaymentsCommand::class)->everyFiveMinutes();

        // Nervous System — agent lifecycle, ledger maintenance, pulse + dashboard
        // rollups, plan + capability sweeps, the daily-learning loop.
        NervousSystemSchedule::register($schedule);

        // Lead follow-up v2 — hourly fan-out + daily summary. Timing map +
        // rationale live in LeadFollowUpSchedule.
        LeadFollowUpSchedule::register($schedule);

        // Scribe — daily AR-aging fan-out (per (app, company) tuple with open AR).
        ScribeSchedule::register($schedule);

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
