<?php

declare(strict_types=1);

namespace App\Console\Commands\Event;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;

class GenerateUpcomingTimeSlotsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:events:generate-upcoming-time-slots {app_id?} {--prune}';

    protected $description = 'Roll the time-slot window forward for active schedule rules and refresh price snapshots within each app booking horizon';

    public function handle(): int
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->processApp((int) $appsId);

            return self::SUCCESS;
        }

        $appsIds = Settings::where([
            'name' => ConfigurationEnum::GENERATE_UPCOMING_TIME_SLOTS->value,
            'value' => '1',
        ])->select('apps_id')->get()->pluck('apps_id');

        $this->info('Generating upcoming time slots for ' . $appsIds->count() . ' apps');

        foreach ($appsIds as $id) {
            $this->processApp((int) $id);
        }

        return self::SUCCESS;
    }

    protected function processApp(int $appsId): void
    {
        /** @var Apps $app */
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $horizonDays = GenerateTimeSlots::horizonDaysForApp($app);
        $dispatched = 0;

        ScheduleRules::query()
            ->notDeleted()
            ->fromApp($app)
            ->where(
                fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', Carbon::now())
            )
            ->orderBy('id')
            ->chunk(
                100,
                function ($rules) use (&$dispatched, $horizonDays) {
                    foreach ($rules as $rule) {
                        [$windowFrom, $windowTo] = GenerateTimeSlots::resolveWindow(
                            $rule->start_at,
                            $rule->end_at,
                            $horizonDays
                        );

                        if ($windowFrom->greaterThanOrEqualTo($windowTo)) {
                            continue;
                        }

                        dispatch(new GenerateTimeSlots(
                            $rule->resources_id,
                            $rule->id,
                            $windowFrom,
                            $windowTo
                        ));
                        $dispatched++;
                    }
                }
            );

        $this->info("App {$appsId}: dispatched {$dispatched} time slot generation jobs.");

        if ($this->option('prune')) {
            $pruned = $this->prunePastUnbookedSlots($app);
            $this->info("App {$appsId}: pruned {$pruned} past unbooked time slots.");
        }
    }

    /**
     * Rule-generated slots that ended in the past and were never booked are dead weight;
     * booked ones stay as history. Batched so a first run over a large table doesn't
     * hold one giant delete transaction.
     */
    protected function prunePastUnbookedSlots(Apps $app): int
    {
        $pruned = 0;

        do {
            $deleted = TimeSlots::query()
                ->fromApp($app)
                ->whereNotNull('schedule_rules_id')
                ->where('end_at', '<', Carbon::now())
                ->whereDoesntHave(
                    'eventVersions',
                    fn ($q) => $q->where('is_deleted', 0)
                )
                ->limit(1000)
                ->forceDelete();

            $pruned += $deleted;
        } while ($deleted > 0);

        return $pruned;
    }
}
