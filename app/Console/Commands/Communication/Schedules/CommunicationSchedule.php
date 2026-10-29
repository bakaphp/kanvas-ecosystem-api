<?php

declare(strict_types=1);

namespace App\Console\Commands\Communication\Schedules;

use App\Console\Commands\Communication\CommunicationSetupCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Communication cron registry. Wired into Kernel.php::schedule().
 *
 *   every 10 min  communication:setup --all-apps
 *                 — the durability sweep: recovers setup a dead worker left half-done, retries
 *                   failed and blocked connections, finishes disconnect cleanup, releases shared
 *                   registrations once their deliveries age out, and reconciles lost outcomes.
 */
final class CommunicationSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(CommunicationSetupCommand::class, ['--all-apps' => true])
            ->everyTenMinutes()
            ->withoutOverlapping(30)
            ->onOneServer();
    }
}
