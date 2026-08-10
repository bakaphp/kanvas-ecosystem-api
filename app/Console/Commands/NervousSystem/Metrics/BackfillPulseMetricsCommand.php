<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Metrics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Pulse\Actions\BackfillPulseMetricsAction;

class BackfillPulseMetricsCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = 'kanvas:backfill-pulse-metrics
        {--days=30 : Number of trailing days to backfill, ending yesterday}
        {--from= : Override start date (YYYY-MM-DD)}
        {--to= : Override end date (YYYY-MM-DD), defaults to yesterday}
        {--app-id= : Restrict to a single app id}
        {--force : Re-roll dates even when a snapshot already exists}';

    protected $description = 'Populate nervous_system_pulse_metrics_daily for past dates from existing ledger + plan data.';

    public function handle(): int
    {
        $to = $this->option('to') !== null
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $from = $this->option('from') !== null
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : $to->copy()->subDays((int) $this->option('days') - 1)->startOfDay();

        if ($from->gt($to)) {
            $this->error('--from cannot be after --to');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backfilling pulse metrics from %s to %s%s%s',
            $from->toDateString(),
            $to->toDateString(),
            $this->option('app-id') !== null ? ' (app id ' . $this->option('app-id') . ')' : '',
            $this->option('force') ? ' [force]' : '',
        ));

        $app = $this->option('app-id') !== null
            ? Apps::find((int) $this->option('app-id'))
            : null;

        if ($this->option('app-id') !== null && $app === null) {
            $this->error('App id ' . $this->option('app-id') . ' not found.');

            return self::FAILURE;
        }

        if ($app !== null) {
            $this->overwriteAppService($app);
        }

        $result = new BackfillPulseMetricsAction(
            from: $from,
            to: $to,
            app: $app,
            force: (bool) $this->option('force'),
        )->execute();

        $this->info(sprintf(
            'Done. Days processed: %d · Rolled up: %d · Skipped (already exists): %d · Tenants cache cleared: %d',
            $result['days_processed'],
            $result['rolled_up'],
            $result['skipped'],
            $result['tenants_cache_cleared'],
        ));

        return self::SUCCESS;
    }
}
