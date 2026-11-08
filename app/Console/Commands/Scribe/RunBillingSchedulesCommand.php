<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\BillingSchedules\Jobs\RunBillingScheduleJob;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleRun;

class RunBillingSchedulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:run-billing-schedules
                            {--today= : ISO date to bill as (defaults to today)}
                            {--sync : Run each schedule inline instead of queueing it}';

    protected $description = 'Create the draft invoice for every billing schedule period that is due.';

    public function handle(): int
    {
        $todayIso = $this->option('today') ?: Carbon::today()->toDateString();
        $today = Carbon::parse($todayIso);

        $resumable = BillingScheduleRun::query()
            ->awaitingSubmission(Carbon::now())
            ->distinct()
            ->pluck('billing_schedule_id')
            ->all();

        $schedules = BillingSchedule::query()
            ->notDeleted()
            ->where(fn (Builder $query): Builder => $query->due($today)->orWhereIn('id', $resumable))
            ->orderBy('apps_id')
            ->get();

        $this->info("{$schedules->count()} billing schedules due or awaiting submission on {$todayIso}");

        foreach ($schedules->groupBy('apps_id') as $appsId => $appSchedules) {
            $app = Apps::find((int) $appsId);

            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);

            foreach ($appSchedules as $schedule) {
                $job = new RunBillingScheduleJob($app, $schedule, $todayIso);

                $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            }
        }

        return self::SUCCESS;
    }
}
