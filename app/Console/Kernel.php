<?php

namespace App\Console;

use App\Console\Commands\Connectors\Notifications\MailCaddieLabCommand;
use App\Console\Commands\Ecosystem\Users\DeleteUsersRequestedCommand;
use App\Console\Commands\ImportPromptsFromDocsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use App\Console\Commands\Ecosystem\Notifications\MailunregisteredUsersCampaignCommand;
use Kanvas\Apps\Models\Apps;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(RunHealthChecksCommand::class)->everyMinute();
        $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
        $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
        $schedule->command(DeleteUsersRequestedCommand::class)->dailyAt('00:00');
        $schedule->command(MailunregisteredUsersCampaignCommand::class)->weeklyOn(2, '2:30');
        $schedule->command(ImportPromptsFromDocsCommand::class)->weeklyOn(1, '00:00');


        /**
         * @todo move this to a cron subSystem
         */
        if (getenv('CADDIE_APP_KEY')) {
            $schedule->command(MailCaddieLabCommand::class, [getenv('CADDIE_APP_KEY')])
                ->dailyAt('13:00')
                ->timezone('America/New_York');
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
