<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SyncAllPeopleInCompanyCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-people-sync {app_id} {company_id} {total=150} {perPage=50}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads from Zoho to this branch';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $perPage = (int) $this->argument('perPage');
        $total = (int) $this->argument('total');
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $hourlyRateLimit = 400;
        $dailyRateLimit = 2000;
        $batchSize = 100;
        $hourlyCacheKey = 'api_hourly_rate_limit_' . $app->getId();
        $dailyCacheKey = 'api_daily_rate_limit_' . $app->getId();
        $resetHourlyKey = 'api_hourly_rate_limit_reset_' . $app->getId();
        $resetDailyKey = 'api_daily_rate_limit_reset_' . $app->getId();
        $hourlyTimeWindow = 60 * 60;
        $dailyTimeWindow = 24 * 60 * 60;

        $resetHourlyTimestamp = Cache::get($resetHourlyKey, 0) instanceof Carbon ? Cache::get($resetHourlyKey, 0)->timestamp : 0;
        $resetDailyTimestamp = Cache::get($resetDailyKey, 0) instanceof Carbon ? Cache::get($resetDailyKey, 0)->timestamp : 0;

        // Reset hourly/daily counters if time window has expired
        if (now()->timestamp >= $resetHourlyTimestamp) {
            Cache::put($hourlyCacheKey, 0, $hourlyTimeWindow);
        }

        if (now()->timestamp >= $resetDailyTimestamp) {
            Cache::put($dailyCacheKey, 0, $dailyTimeWindow);
        }

        $currentHourlyCount = Cache::get($hourlyCacheKey, 0);
        $currentDailyCount = Cache::get($dailyCacheKey, 0);

        $this->line("Syncing people for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}");

        People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->orderBy('peoples.id', 'DESC')
            ->limit($total)
            ->chunk($perPage, function ($peoples) use (&$currentHourlyCount, &$currentDailyCount, $hourlyRateLimit, $dailyRateLimit, $hourlyCacheKey, $dailyCacheKey, $resetHourlyKey, $resetDailyKey, $hourlyTimeWindow, $dailyTimeWindow) {
                foreach ($peoples as $people) {
                    $hasCustomField = $people->get(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value);
                    if ($hasCustomField) {
                        continue;
                    }

                    if ($currentHourlyCount >= $hourlyRateLimit) {
                        Cache::put($resetHourlyKey, now()->addSeconds($hourlyTimeWindow), $hourlyTimeWindow);
                        $this->line('Hourly rate limit reached. Waiting for reset...');
                        sleep($hourlyTimeWindow);

                        continue;
                    }

                    if ($currentDailyCount >= $dailyRateLimit) {
                        Cache::put($resetDailyKey, now()->addSeconds($dailyTimeWindow), $dailyTimeWindow);
                        $this->line('Daily rate limit reached. Waiting for reset...');
                        sleep($dailyTimeWindow);

                        continue;
                    }

                    $this->line("Syncing people {$people->id}: {$people->firstname} {$people->lastname}");

                    $people->fireWorkflow(
                        WorkflowEnum::UPDATED->value,
                        true,
                        ['app' => $people->app]
                    );

                    $currentHourlyCount++;
                    $currentDailyCount++;

                    Cache::put($hourlyCacheKey, $currentHourlyCount, $hourlyTimeWindow);
                    Cache::put($dailyCacheKey, $currentDailyCount, $dailyTimeWindow);

                    // Dynamic delay based on remaining rate limit
                    $delay = $this->calculateDelay($currentHourlyCount, $hourlyRateLimit);
                    sleep($delay);
                }
            });

        $this->line("All people for company {$company->name} from app {$app->name} synced");
    }

    private function calculateDelay(int $currentCount, int $rateLimit): int
    {
        // Adjust delay dynamically to distribute requests evenly
        $remainingRequests = $rateLimit - $currentCount;
        $remainingTime = 60 * 60; // 1 hour in seconds

        return $remainingRequests > 0 ? intdiv($remainingTime, $remainingRequests) : 2;
    }
}
