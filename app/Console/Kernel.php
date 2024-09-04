<?php

namespace App\Console;

use App\Console\Commands\Connectors\Notifications\MailCaddieLabCommand;
use App\Console\Commands\Ecosystem\Users\DeleteUsersRequestedCommand;
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

        /**
         * @todo move this to a cron subSystem
         */
        if (getenv('CADDIE_APP_KEY')) {
            $schedule->command(MailCaddieLabCommand::class, [getenv('CADDIE_APP_KEY')])
                ->dailyAt('13:00')
                ->timezone('America/New_York');
        }

        $app = Apps::find(getenv('CUSTOM_APP_ID'));
        $campaignWeek = (int)ceil(now()->day / 7);
        $dayOftheWeek = 2;
        $timeOfDay = '2:30';

        switch ($campaignWeek) {
            case 1:
                $schedule->command(MailunregisteredUsersCampaignCommand::class, [
                    $app->getId(), 'introducing_prompt_mine', 'Discover Your New Creative Tool: Introducing Prompt Mine'
                ])->weeklyOn($dayOftheWeek, $timeOfDay);
                break;

            case 2:
                $schedule->command(MailunregisteredUsersCampaignCommand::class, [
                    $app->getId(), 'get_inspired_with_prompt_mine', 'Get Inspired with Prompt Mine'
                ])->weeklyOn($dayOftheWeek, $timeOfDay);
                break;

            case 3:
                $schedule->command(MailunregisteredUsersCampaignCommand::class, [
                    $app->getId(), 'see_what_trending_on_prompt_mine', ''
                ])->weeklyOn($dayOftheWeek, $timeOfDay);
                break;

            case 4:
                $schedule->command(MailunregisteredUsersCampaignCommand::class, [
                    $app->getId(), 'elevate_your_ai_experience', 'Elevate Your AI Experience with Prompt Mine'
                ])->weeklyOn($dayOftheWeek, $timeOfDay);
                break;
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
