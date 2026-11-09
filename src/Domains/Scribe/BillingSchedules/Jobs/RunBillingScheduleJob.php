<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\BillingSchedules\Actions\RunBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;

/**
 * Bills every period of one schedule that is due by $todayIso. A schedule that fell behind (the
 * scheduler was down, a paused schedule resumed) catches up one invoice per missed period, capped so
 * a misconfigured start date cannot flood a customer.
 */
final class RunBillingScheduleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int MAX_PERIODS_PER_RUN = 12;

    public function __construct(
        public readonly BillingSchedule $schedule,
        public readonly ?string $todayIso = null,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->schedule->app);

        $today = $this->todayIso !== null ? Carbon::parse($this->todayIso) : Carbon::today();

        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN; $i++) {
            $invoice = new RunBillingScheduleAction($this->schedule->refresh(), $today)->execute();

            if ($invoice === null) {
                return;
            }
        }
    }
}
