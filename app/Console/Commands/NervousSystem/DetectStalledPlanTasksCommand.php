<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Plan\Actions\DetectStalledTasksAction;
use Throwable;

class DetectStalledPlanTasksCommand extends Command
{
    protected $signature = 'nervous-system:detect-stalled-plan-tasks
        {--stalled-after-minutes=30 : Threshold in minutes — tasks in_progress longer than this emit a plan.task.stalled event}';

    protected $description = 'Detect Nervous System plan tasks that have been in_progress longer than the threshold and emit plan.task.stalled events';

    public function handle(): int
    {
        $threshold = (int) $this->option('stalled-after-minutes');

        $this->info(sprintf('Scanning tasks in_progress > %d minutes', $threshold));

        try {
            $result = new DetectStalledTasksAction(stalledAfterMinutes: $threshold)->execute();
        } catch (Throwable $e) {
            $this->error('Stalled-task detection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Checked %d stalled tasks, emitted %d plan.task.stalled events.',
            $result['checked'],
            $result['emitted'],
        ));

        return self::SUCCESS;
    }
}
