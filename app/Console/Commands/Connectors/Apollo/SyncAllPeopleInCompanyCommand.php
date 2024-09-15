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
use Kanvas\CustomFields\Models\AppsCustomFields;
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
    protected $signature = 'kanvas:guild-apollo-people-sync {app_id} {company_id} {total=200} {perPage=200}';

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

        $hourlyRateLimit = 400; // Maximum API calls per hour
        $dailyRateLimit = 2000; // Maximum API calls per day
        $batchSize = 100; // Number of people to process per batch
        $hourlyCacheKey = 'api_hourly_rate_limit_' . $app->getId();
        $dailyCacheKey = 'api_daily_rate_limit_' . $app->getId();
        $resetHourlyKey = 'api_hourly_rate_limit_reset_' . $app->getId();
        $resetDailyKey = 'api_daily_rate_limit_reset_' . $app->getId();
        $hourlyTimeWindow = 60 * 60; // 1 hour in seconds
        $dailyTimeWindow = 24 * 60 * 60; // 24 hours in seconds

        // Check the current count of API calls
        $currentHourlyCount = Cache::get($hourlyCacheKey, 0);
        $currentDailyCount = Cache::get($dailyCacheKey, 0);
        $resetHourlyTimestamp = Cache::get($resetHourlyKey);
        $resetDailyTimestamp = Cache::get($resetDailyKey);

        $this->line('Syncing ' . $currentHourlyCount . ' people in company ' . $company->name . ' from app ' . $app->name . ' total ' . $total . ' per page ' . $perPage);

        if ($resetHourlyTimestamp) {
            $resetHourlyTime = Carbon::parse($resetHourlyTimestamp);
            $currentTimestamp = now()->timestamp;
            $hourlyWaitTime = $resetHourlyTime->timestamp - $currentTimestamp;

            if ($currentHourlyCount >= $hourlyRateLimit && $hourlyWaitTime > 0) {
                $this->line("Hourly rate limit reached. Please wait $hourlyWaitTime seconds to run the process again.");

                return;
            }
        }

        if ($resetDailyTimestamp) {
            $resetDailyTime = Carbon::parse($resetDailyTimestamp);
            $currentTimestamp = now()->timestamp;
            $dailyWaitTime = $resetDailyTime->timestamp - $currentTimestamp;

            if ($currentDailyCount >= $dailyRateLimit && $dailyWaitTime > 0) {
                $this->line("Daily rate limit reached. Please wait $dailyWaitTime seconds to run the process again.");

                return;
            }
        }

        People::fromApp($app)
            ->fromCompany($company)
            ->leftJoinSub(
                AppsCustomFields::select('entity_id')
                    ->where('name', ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value),
                'acf',
                'peoples.id',
                '=',
                'acf.entity_id'
            )
            ->whereNull('acf.entity_id')
            ->take($total)  // Limit the query to 200 results
            ->orderBy('peoples.id', 'asc')
            ->chunk($batchSize, function ($peoples) use (&$currentHourlyCount, &$currentDailyCount, $hourlyRateLimit, $dailyRateLimit, $hourlyCacheKey, $dailyCacheKey, $resetHourlyKey, $resetDailyKey, $hourlyTimeWindow, $dailyTimeWindow) {
                foreach ($peoples as $people) {
                    if ($currentHourlyCount >= $hourlyRateLimit) {
                        Cache::put($resetHourlyKey, now()->addSeconds($hourlyTimeWindow), $hourlyTimeWindow);
                        $this->line("Hourly rate limit reached. Please wait $hourlyTimeWindow seconds to run the process again.");

                        return false;
                    }

                    if ($currentDailyCount >= $dailyRateLimit) {
                        Cache::put($resetDailyKey, now()->addSeconds($dailyTimeWindow), $dailyTimeWindow);
                        $this->line("Daily rate limit reached. Please wait $dailyTimeWindow seconds to run the process again.");

                        return false;
                    }

                    $this->line('Syncing people ' . $people->id . ' ' . $people->firstname . ' ' . $people->lastname);

                    $people->fireWorkflow(
                        WorkflowEnum::UPDATED->value,
                        true,
                        [
                            'app' => $people->app,
                        ]
                    );
                    $people->clearLightHouseCacheJob();

                    $currentHourlyCount++;
                    $currentDailyCount++;
                    Cache::put($hourlyCacheKey, $currentHourlyCount, $hourlyTimeWindow);
                    Cache::put($dailyCacheKey, $currentDailyCount, $dailyTimeWindow);

                    usleep(100000); // 100ms delay between each request
                }
            });

        $this->line('All people in company ' . $company->name . ' from app ' . $app->name . ' synced');

        return;
    }
}
